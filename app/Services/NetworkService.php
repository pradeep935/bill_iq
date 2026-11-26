<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\SalesVoucher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Who may see whose bills. A bill of another business is readable only when
 *  (a) it was sent to this business over an accepted connection, or
 *  (b) this business holds the bill's share link.
 * Every read of another business's data starts here.
 */
class NetworkService
{
    private const INACTIVE_PURCHASE = ['cancelled', 'reversed'];

    public static function ready(): bool
    {
        return Schema::hasTable('network_connections') && Schema::hasTable('network_imports');
    }

    /* ---------- connections ---------- */

    /** Finds businesses by their full GSTIN or full 10-digit phone number only (no partial search). */
    public function lookup(string $term): array
    {
        $me = AppController::businessId();
        $gstin = strtoupper(preg_replace('/\s+/', '', $term));
        $phone = $this->phone($term);

        if (strlen($gstin) !== 15 && !$phone) {
            throw ValidationException::withMessages(['q' => 'Enter the full 10 digit mobile number or the full 15 character GSTIN.']);
        }

        return DB::table('companies')->where('id', '!=', $me)->get(['id', 'name', 'state', 'gstin', 'phone'])
            ->filter(fn ($company) => (strlen($gstin) === 15 && strtoupper((string) $company->gstin) === $gstin) || ($phone && $this->phone($company->phone) === $phone))
            ->map(fn ($company) => $this->publicProfile($company) + ['connection' => optional($this->connectionBetween($me, $company->id))->status])
            ->values()->all();
    }

    public function request(int $target, string $term): string
    {
        $me = AppController::businessId();
        // The target must be a match for what was typed, so ids cannot be guessed.
        if (!collect($this->lookup($term))->contains('id', $target)) {
            throw ValidationException::withMessages(['q' => 'This business does not match that number or GSTIN.']);
        }

        return DB::transaction(function () use ($me, $target) {
            $existing = $this->connectionBetween($me, $target);

            if ($existing) {
                if ($existing->status === 'accepted') {
                    throw ValidationException::withMessages(['q' => 'You are already connected.']);
                }
                if ($existing->status === 'pending') {
                    throw ValidationException::withMessages(['q' => (int) $existing->requester_business_id === $me
                        ? 'Request already sent. Waiting for them to accept.'
                        : 'They have already sent you a request. Accept it under Connections.']);
                }
                if ($existing->status === 'rejected' && (int) $existing->requester_business_id === $me) {
                    throw ValidationException::withMessages(['q' => 'This business declined your request. Ask them to send you a request instead.']);
                }
                DB::table('network_connections')->where('id', $existing->id)->delete();
            }

            DB::table('network_connections')->insert([
                'requester_business_id' => $me, 'receiver_business_id' => $target, 'status' => 'pending',
                'requested_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return 'Request sent. Bills can be shared after they accept.';
        });
    }

    public function respond(int $id, string $action): string
    {
        $me = AppController::businessId();
        $row = DB::table('network_connections')->where('id', $id)
            ->where(fn ($q) => $q->where('requester_business_id', $me)->orWhere('receiver_business_id', $me))->first();
        abort_unless($row, 404);

        if (in_array($action, ['accept', 'reject'], true)) {
            // Only the business that was asked can answer.
            abort_unless((int) $row->receiver_business_id === $me, 403);
            if ($row->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This request was already answered.']);
            }
            $status = $action === 'accept' ? 'accepted' : 'rejected';
        } else {
            // A declined request stays declined; removing it would let the asker send it again.
            if (!in_array($row->status, ['pending', 'accepted'], true)) {
                throw ValidationException::withMessages(['status' => 'There is no connection to remove.']);
            }
            $status = 'removed';
        }

        DB::table('network_connections')->where('id', $id)->update(['status' => $status, 'responded_by' => Auth::id(), 'updated_at' => now()]);

        return ['accepted' => 'Connected.', 'rejected' => 'Request declined.', 'removed' => 'Connection removed.'][$status];
    }

    public function overview(): array
    {
        $me = AppController::businessId();
        $company = DB::table('companies')->where('id', $me)->first(['id', 'name', 'state', 'gstin', 'phone']);
        $connections = DB::table('network_connections')
            ->where(fn ($q) => $q->where('requester_business_id', $me)->orWhere('receiver_business_id', $me))
            ->whereIn('status', ['pending', 'accepted'])->orderByDesc('id')->get();
        $others = DB::table('companies')
            ->whereIn('id', $connections->map(fn ($c) => (int) $c->requester_business_id === $me ? $c->receiver_business_id : $c->requester_business_id))
            ->get(['id', 'name', 'state', 'gstin', 'phone'])->keyBy('id');

        return [
            'me' => ['name' => $company->name, 'gstin' => $company->gstin, 'phone' => $company->phone],
            'connections' => $connections->map(function ($c) use ($me, $others) {
                $outgoing = (int) $c->requester_business_id === $me;
                $other = $others->get($outgoing ? $c->receiver_business_id : $c->requester_business_id);

                return $other ? [
                    'id' => $c->id, 'status' => $c->status, 'direction' => $outgoing ? 'sent' : 'received',
                    // The phone number is shown once it matters: to the business being asked, and between connected ones.
                    'business' => $this->publicProfile($other) + ['phone' => (!$outgoing || $c->status === 'accepted') ? $other->phone : null],
                ] : null;
            })->filter()->values(),
            'incoming' => $this->incoming($me),
        ];
    }

    /* ---------- sending ---------- */

    public function sendOptions(SalesVoucher $voucher): array
    {
        $me = $this->assertSendable($voucher);
        $gstin = strtoupper((string) $voucher->customer_gstin_snapshot);
        $phone = $this->phone($voucher->customer_mobile_snapshot);

        return DB::table('companies')->whereIn('id', $this->connectedIds($me))->orderBy('name')->get(['id', 'name', 'state', 'gstin', 'phone'])
            ->map(fn ($company) => $this->publicProfile($company) + [
                'matches_customer' => ($gstin !== '' && strtoupper((string) $company->gstin) === $gstin) || ($phone && $this->phone($company->phone) === $phone),
                'already_sent' => DB::table('network_bills')->where('to_business_id', $company->id)->where('sales_voucher_id', $voucher->id)->where('status', 'sent')->exists(),
            ])->sortByDesc('matches_customer')->values()->all();
    }

    public function send(SalesVoucher $voucher, int $target): string
    {
        $me = $this->assertSendable($voucher);
        if (!in_array($target, $this->connectedIds($me), true)) {
            throw ValidationException::withMessages(['business_id' => 'You can send bills only to connected businesses.']);
        }

        $key = ['to_business_id' => $target, 'sales_voucher_id' => $voucher->id];
        if (DB::table('network_bills')->where($key)->exists()) {
            DB::table('network_bills')->where($key)->update(['status' => 'sent', 'sent_by' => Auth::id(), 'updated_at' => now()]);
        } else {
            DB::table('network_bills')->insert($key + ['from_business_id' => $me, 'status' => 'sent', 'sent_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return 'Bill sent. It now shows in their Supplier Bills.';
    }

    public function dismiss(int $billId): void
    {
        $updated = DB::table('network_bills')->where('id', $billId)->where('to_business_id', AppController::businessId())
            ->update(['status' => 'dismissed', 'updated_at' => now()]);
        abort_unless($updated, 404);
    }

    /* ---------- receiving ---------- */

    private function incoming(int $me): array
    {
        $rows = DB::table('network_bills as b')
            ->join('sales_vouchers as s', function ($join) {
                $join->on('s.id', '=', 'b.sales_voucher_id')->on('s.business_id', '=', 'b.from_business_id');
            })
            ->join('companies as c', 'c.id', '=', 'b.from_business_id')
            ->where('b.to_business_id', $me)->where('b.status', 'sent')
            ->whereIn('b.from_business_id', $this->connectedIds($me))
            ->orderByDesc('b.id')->limit(200)
            ->get(['b.id', 'b.sales_voucher_id', 'b.created_at', 'c.name as supplier', 's.invoice_number', 's.invoice_date', 's.grand_total', 's.status']);

        return $rows->map(function ($row) use ($me) {
            $purchase = $this->activeImport($me, (int) $row->sales_voucher_id);
            $valid = in_array($row->status, CustomerClassificationService::VALID_SALE_STATUSES, true);

            return [
                'id' => $row->id, 'supplier' => $row->supplier, 'invoice_number' => $row->invoice_number,
                'invoice_date' => substr((string) $row->invoice_date, 0, 10),
                // A bill the supplier cancelled is listed without its amount.
                'grand_total' => $valid ? (float) $row->grand_total : null,
                'received_on' => substr((string) $row->created_at, 0, 10),
                'state' => $purchase ? 'added' : ($valid ? 'new' : 'cancelled'),
                'purchase_number' => $purchase->voucher_number ?? null,
            ];
        })->all();
    }

    /** The only way to get another business's bill. $source is ['bill_id' => n] or ['token' => 'link or token']. */
    public function resolveSource(array $source): SalesVoucher
    {
        $me = AppController::businessId();

        if (!empty($source['bill_id'])) {
            $bill = DB::table('network_bills')->where('id', $source['bill_id'])->where('to_business_id', $me)->where('status', 'sent')->first();
            if (!$bill || !in_array((int) $bill->from_business_id, $this->connectedIds($me), true)) {
                throw ValidationException::withMessages(['source' => 'This bill is no longer available to you.']);
            }
            $voucher = SalesVoucher::query()->whereKey($bill->sales_voucher_id)->where('business_id', $bill->from_business_id)
                ->whereIn('status', CustomerClassificationService::VALID_SALE_STATUSES)->first();
            if (!$voucher) {
                throw ValidationException::withMessages(['source' => 'The supplier has cancelled or changed this bill. Ask them to send it again.']);
            }

            return $voucher;
        }

        // Accepts the pasted link, a whole WhatsApp message containing it, or the bare token.
        $text = trim((string) ($source['token'] ?? ''));
        $token = preg_match('~/i/([A-Za-z0-9]{48})(?![A-Za-z0-9])~', $text, $match) ? $match[1] : (preg_match('~^[A-Za-z0-9]{48}$~', $text) ? $text : null);
        $voucher = $token ? SalesVoucher::query()->where('public_token', $token)->where('public_share_enabled', true)
            ->whereIn('status', CustomerClassificationService::VALID_SALE_STATUSES)->first() : null;

        if (!$voucher) {
            throw ValidationException::withMessages(['source' => 'No bill found for this link. Paste the full BillIQ bill link the supplier sent you.']);
        }
        if ((int) $voucher->business_id === $me) {
            throw ValidationException::withMessages(['source' => 'This is your own sales bill. Paste a bill link sent by your supplier.']);
        }

        return $voucher;
    }

    /** The purchase already made from this bill, if it still counts (drafts count; cancelled and reversed do not). */
    public function activeImport(int $businessId, int $salesVoucherId): ?object
    {
        return DB::table('network_imports as i')->join('purchase_vouchers as p', 'p.id', '=', 'i.purchase_voucher_id')
            ->where('i.business_id', $businessId)->where('i.sales_voucher_id', $salesVoucherId)
            ->where('p.business_id', $businessId)->whereNotIn('p.status', self::INACTIVE_PURCHASE)
            ->first(['p.id', 'p.voucher_number', 'p.status']);
    }

    public function assertNotEntered(SalesVoucher $source, ?int $supplierId): void
    {
        $me = AppController::businessId();

        if ($existing = $this->activeImport($me, $source->id)) {
            throw ValidationException::withMessages(['source' => "This bill is already added as {$existing->voucher_number} ({$existing->status})."]);
        }
        // Also catches a bill that was typed in by hand.
        if ($supplierId) {
            $typed = DB::table('purchase_vouchers')->where('business_id', $me)->where('supplier_id', $supplierId)
                ->where('supplier_invoice_number', $source->invoice_number)->whereNotIn('status', self::INACTIVE_PURCHASE)->value('voucher_number');
            if ($typed) {
                throw ValidationException::withMessages(['source' => "Bill no. {$source->invoice_number} of this supplier is already entered as {$typed}."]);
            }
        }
    }

    public function recordImport(SalesVoucher $source, int $purchaseId): void
    {
        DB::table('network_imports')->insert([
            'business_id' => AppController::businessId(), 'from_business_id' => $source->business_id,
            'sales_voucher_id' => $source->id, 'purchase_voucher_id' => $purchaseId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Editing a posted purchase replaces its row; the link to the received bill moves with it. */
    public static function moveImport(int $fromPurchaseId, int $toPurchaseId): void
    {
        if (self::ready()) {
            DB::table('network_imports')->where('purchase_voucher_id', $fromPurchaseId)->update(['purchase_voucher_id' => $toPurchaseId, 'updated_at' => now()]);
        }
    }

    public function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    private function publicProfile(object $company): array
    {
        return ['id' => (int) $company->id, 'name' => $company->name, 'state' => $company->state, 'gstin' => $company->gstin];
    }

    private function connectionBetween(int $a, int $b): ?object
    {
        return DB::table('network_connections')->where(fn ($q) => $q
            ->where(fn ($x) => $x->where('requester_business_id', $a)->where('receiver_business_id', $b))
            ->orWhere(fn ($x) => $x->where('requester_business_id', $b)->where('receiver_business_id', $a)))->first();
    }

    private function connectedIds(int $me): array
    {
        return DB::table('network_connections')->where('status', 'accepted')
            ->where(fn ($q) => $q->where('requester_business_id', $me)->orWhere('receiver_business_id', $me))->get()
            ->map(fn ($c) => (int) ((int) $c->requester_business_id === $me ? $c->receiver_business_id : $c->requester_business_id))->all();
    }

    private function assertSendable(SalesVoucher $voucher): int
    {
        $me = AppController::businessId();
        abort_unless((int) $voucher->business_id === $me, 404);
        if (!in_array($voucher->status, CustomerClassificationService::VALID_SALE_STATUSES, true)) {
            throw ValidationException::withMessages(['invoice' => 'Only posted invoices can be sent.']);
        }

        return $me;
    }
}
