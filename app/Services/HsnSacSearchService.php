<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\HsnMaster;
use App\Models\HsnTaxRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HsnSacSearchService
{
    public function search(array $filters): array
    {
        $businessId = AppController::businessId();
        $queryText = trim((string) ($filters['q'] ?? ''));
        $productName = trim((string) ($filters['product_name'] ?? ''));
        $codeType = $filters['code_type'] ?? (($filters['product_type'] ?? '') === 'service' ? 'SAC' : 'HSN');
        $codeType = strtoupper((string) $codeType);
        $codeType = in_array($codeType, ['HSN', 'SAC'], true) ? $codeType : 'HSN';
        $categoryId = $filters['category_id'] ?? null;
        $date = $filters['transaction_date'] ?? now()->toDateString();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(max((int) ($filters['limit'] ?? 20), 1), 50);
        $searchText = trim($queryText . ' ' . $productName);
        $tokens = $this->tokens($searchText);
        $directCodeSearch = preg_match('/^\d{2,8}$/', $queryText) === 1;

        if ($searchText !== '' && !$tokens && !$directCodeSearch) {
            return [
                'data' => [],
                'meta' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                ],
            ];
        }

        $query = HsnMaster::query()
            ->where('code_type', $codeType)
            ->where('business_id', $businessId)
            ->where(function (Builder $scope) {
                $scope->whereNull('status')->orWhere('status', 'active');
            });

        if ($searchText !== '') {
            $query->where(function (Builder $scope) use ($searchText, $tokens, $directCodeSearch, $queryText) {
                if ($directCodeSearch) {
                    $scope->where('hsn_code', 'like', $queryText . '%');
                }

                if (strlen($searchText) >= 3) {
                    $scope->orWhere('description', 'like', '%' . $searchText . '%');

                    if (Schema::hasColumn('hsn_masters', 'search_keywords')) {
                        $scope->orWhere('search_keywords', 'like', '%' . $searchText . '%');
                    }
                }

                foreach ($tokens as $token) {
                    $scope->orWhere('description', 'like', '%' . $token . '%');
                    if (Schema::hasColumn('hsn_masters', 'search_keywords')) {
                        $scope->orWhere('search_keywords', 'like', '%' . $token . '%');
                    }
                }
            });
        }

        $candidateLimit = min(max($limit * 100, 300), 3000);
        $candidates = $query
            ->limit($candidateLimit)
            ->get();

        $similar = $this->similarProductUsage($businessId, $codeType, $tokens);
        $category = $this->categoryMappings($businessId, $categoryId, $tokens);
        $usage = $this->businessUsage($businessId);

        $ranked = $candidates
            ->map(function (HsnMaster $hsn) use ($searchText, $tokens, $similar, $category, $usage, $date) {
                $score = $this->score($hsn, $searchText, $tokens, $similar, $category, $usage);
                $matchSource = $this->matchSource($hsn, $similar, $category, $usage);

                return [
                    'score' => $score,
                    'id' => $hsn->id,
                    'code' => $hsn->hsn_code,
                    'hsn_code' => $hsn->hsn_code,
                    'code_type' => $hsn->code_type,
                    'description' => $hsn->description,
                    'chapter_code' => $hsn->chapter_code,
                    'match_source' => $matchSource,
                    'similar_product_count' => (int) ($similar[$hsn->id] ?? 0),
                    'usage_count' => (int) ($usage[$hsn->id]['usage_count'] ?? 0),
                    'last_used_at' => $usage[$hsn->id]['last_used_at'] ?? null,
                    'classification_verified' => $this->classificationVerified($hsn),
                    'rate_verified' => $this->rateVerified($hsn),
                    'taxability' => $hsn->taxability,
                    'gst_rate' => $hsn->gst_rate !== null ? (float) $hsn->gst_rate : null,
                    'cess_rate' => $hsn->cess_rate !== null ? (float) $hsn->cess_rate : null,
                    'tax_resolution' => $this->taxResolution($hsn->id, $date),
                ];
            })
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->values();

        $offset = ($page - 1) * $limit;

        return [
            'data' => $ranked->slice($offset, $limit)->values(),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $ranked->count(),
            ],
        ];
    }

    public function taxResolution(int $hsnId, ?string $date = null): array
    {
        if (!Schema::hasTable('hsn_tax_rates')) {
            return ['status' => 'no_verified_rule', 'rules' => []];
        }

        $date ??= now()->toDateString();

        $rules = HsnTaxRate::query()
            ->where('hsn_id', $hsnId)
            ->where('status', 'active')
            ->where('verification_status', 'verified')
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->limit(5)
            ->get();

        if ($rules->isEmpty()) {
            return ['status' => 'no_verified_rule', 'rules' => []];
        }

        $present = $rules->map(fn (HsnTaxRate $rule) => [
            'id' => $rule->id,
            'gst_rate' => $rule->gst_rate !== null ? (float) $rule->gst_rate : null,
            'cess_rate' => $rule->cess_rate !== null ? (float) $rule->cess_rate : null,
            'taxability' => $rule->taxability,
            'rule_name' => $rule->rule_name,
            'rule_description' => $rule->rule_description,
            'condition_text' => $rule->condition_text,
            'effective_from' => optional($rule->effective_from)->format('Y-m-d'),
            'effective_to' => optional($rule->effective_to)->format('Y-m-d'),
            'notification_number' => $rule->notification_number,
            'source_reference' => $rule->source_reference,
        ])->values();

        return [
            'status' => $rules->count() === 1 ? 'single_verified_rule' : 'multiple_verified_rules',
            'rule' => $rules->count() === 1 ? $present->first() : null,
            'rules' => $present,
        ];
    }

    private function score(HsnMaster $hsn, string $searchText, array $tokens, array $similar, array $category, array $usage): int
    {
        $code = strtolower((string) $hsn->hsn_code);
        $description = strtolower((string) $hsn->description);
        $keywords = strtolower((string) ($hsn->search_keywords ?? ''));
        $search = $this->normalizeSearchText($searchText);
        $score = 0;

        if ($search !== '' && $code === $search) {
            $score += 1000;
        } elseif ($search !== '' && str_starts_with($code, $search)) {
            $score += 700;
        }

        $score += min(250, (int) ($similar[$hsn->id] ?? 0) * 80);
        $score += (int) ($category[$hsn->id] ?? 0);

        if ($search !== '' && ($description === $search || $keywords === $search)) {
            $score += 450;
        }

        if ($search !== '' && strlen($search) >= 5 && str_contains($description . ' ' . $keywords, $search)) {
            $score += 520;
        }

        if ($search !== '' && str_starts_with($description, $search)) {
            $score += 300;
        }

        if ($search !== '' && strlen($search) >= 3 && $this->containsWords($description . ' ' . $keywords, $this->tokens($search))) {
            $score += 180;
        }

        foreach ($tokens as $token) {
            if ($this->containsWord($description, $token) || $this->containsWord($keywords, $token)) {
                $score += 80;
            }
        }

        if ($hsn->gst_rate !== null) {
            $score += 25;
        }

        $score += min(120, (int) ($usage[$hsn->id]['usage_count'] ?? 0) * 5);
        if (!empty($usage[$hsn->id]['last_used_at'])) {
            $score += 40;
        }

        return $score;
    }

    private function matchSource(HsnMaster $hsn, array $similar, array $category, array $usage): string
    {
        if (!empty($similar[$hsn->id])) {
            return 'similar_product';
        }

        if (!empty($category[$hsn->id])) {
            return 'category_mapping';
        }

        if (!empty($usage[$hsn->id]['usage_count'])) {
            return 'business_usage';
        }

        return 'classification_match';
    }

    private function classificationVerified(HsnMaster $hsn): bool
    {
        if (Schema::hasColumn('hsn_masters', 'classification_verified')) {
            return (bool) $hsn->classification_verified;
        }

        return in_array(strtolower((string) $hsn->verification_status), ['verified', 'classification_verified', 'classification verified', 'rate_suggested', 'rate suggested', 'rate_verified', 'rate verified'], true);
    }

    private function rateVerified(HsnMaster $hsn): bool
    {
        if (Schema::hasColumn('hsn_masters', 'rate_verified')) {
            return (bool) $hsn->rate_verified;
        }

        return in_array(strtolower((string) $hsn->verification_status), ['verified', 'rate_verified', 'rate verified'], true);
    }

    private function similarProductUsage(int $businessId, string $codeType, array $tokens): array
    {
        if (!$tokens || !Schema::hasTable('products') || !Schema::hasColumn('products', 'hsn_master_id')) {
            return [];
        }

        $query = DB::table('products')
            ->join('hsn_masters', 'hsn_masters.id', '=', 'products.hsn_master_id')
            ->where('hsn_masters.code_type', $codeType)
            ->where(function ($scope) use ($businessId) {
                $scope->where('products.business_id', $businessId)->orWhere('products.company_id', $businessId);
            });

        $query->where(function ($scope) use ($tokens) {
            foreach ($tokens as $token) {
                $scope->orWhere('products.name', 'like', '%' . $token . '%')
                    ->orWhere('products.product_name', 'like', '%' . $token . '%')
                    ->orWhere('products.description', 'like', '%' . $token . '%');
            }
        });

        return $query
            ->groupBy('products.hsn_master_id')
            ->pluck(DB::raw('COUNT(*)'), 'products.hsn_master_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function categoryMappings(int $businessId, mixed $categoryId, array $tokens): array
    {
        if (!Schema::hasTable('category_hsn_mappings')) {
            return [];
        }

        $query = DB::table('category_hsn_mappings')
            ->where(function ($scope) use ($businessId) {
                $scope->whereNull('business_id')->orWhere('business_id', $businessId);
            })
            ->where(function ($scope) {
                $scope->whereNull('status')->orWhere('status', 'active')->orWhere('status', 1);
            });

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($tokens && Schema::hasColumn('category_hsn_mappings', 'keyword')) {
            $query->where(function ($scope) use ($tokens) {
                foreach ($tokens as $token) {
                    $scope->orWhere('keyword', 'like', '%' . $token . '%');
                }
            });
        }

        return $query
            ->select('hsn_id', DB::raw('MAX(300 - COALESCE(priority, 100)) as score'))
            ->whereNotNull('hsn_id')
            ->groupBy('hsn_id')
            ->pluck('score', 'hsn_id')
            ->map(fn ($score) => (int) $score)
            ->all();
    }

    private function businessUsage(int $businessId): array
    {
        if (!Schema::hasTable('business_hsn_usage')) {
            return [];
        }

        return DB::table('business_hsn_usage')
            ->where('business_id', $businessId)
            ->get(['hsn_id', 'usage_count', 'last_used_at'])
            ->keyBy('hsn_id')
            ->map(fn ($row) => ['usage_count' => (int) $row->usage_count, 'last_used_at' => $row->last_used_at])
            ->all();
    }

    private function tokens(string $text): array
    {
        $text = $this->normalizeSearchText($text);
        preg_match_all('/[a-z0-9]+/i', strtolower($text), $matches);

        return collect($matches[0] ?? [])
            ->filter(fn (string $token) => strlen($token) >= 3 || ctype_digit($token))
            ->unique()
            ->values()
            ->all();
    }

    private function containsWords(string $text, array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!$this->containsWord($text, $token)) {
                return false;
            }
        }

        return (bool) $tokens;
    }

    private function containsWord(string $text, string $token): bool
    {
        return preg_match('/(^|[^a-z0-9])' . preg_quote(strtolower($token), '/') . '([^a-z0-9]|$)/i', $text) === 1;
    }

    private function normalizeSearchText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', str_ireplace(['musted', 'musturd'], 'mustard', strtolower($text))));
    }
}
