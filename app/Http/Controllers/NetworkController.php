<?php

namespace App\Http\Controllers;

use App\Models\SalesVoucher;
use App\Services\NetworkImportService;
use App\Services\NetworkService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class NetworkController extends Controller
{
    public function __construct(private NetworkService $network, private NetworkImportService $imports)
    {
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('purchases')) {
            return $redirect;
        }

        return Inertia::render('Purchase/Network', ['page' => 'supplier-bills', 'title' => 'Supplier Bills']);
    }

    public function data(Request $request)
    {
        $this->allow($request, 'purchases');

        return response()->json($this->network->overview());
    }

    public function lookup(Request $request)
    {
        $this->allow($request, 'purchases');
        $data = $request->validate(['q' => ['required', 'string', 'max:30']]);

        return response()->json(['businesses' => $this->network->lookup($data['q'])]);
    }

    public function connect(Request $request)
    {
        $this->allow($request, 'purchases');
        $data = $request->validate(['q' => ['required', 'string', 'max:30'], 'business_id' => ['required', 'integer']]);

        return response()->json(['message' => $this->network->request((int) $data['business_id'], $data['q'])]);
    }

    public function respond(Request $request, int $connection, string $action)
    {
        $this->allow($request, 'purchases');

        return response()->json(['message' => $this->network->respond($connection, $action)]);
    }

    public function preview(Request $request)
    {
        $this->allow($request, 'purchases');

        return response()->json($this->imports->preview($this->source($request)));
    }

    public function prepare(Request $request)
    {
        $this->allow($request, 'purchases');
        $choices = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.source_item_id' => ['required', 'integer'],
            'items.*.action' => ['required', Rule::in(['link', 'create', 'charge'])],
            'items.*.product_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->imports->prepare($this->source($request), $choices));
    }

    public function dismiss(Request $request, int $bill)
    {
        $this->allow($request, 'purchases');
        $this->network->dismiss($bill);

        return response()->json(['message' => 'Bill removed from the list.']);
    }

    public function sendOptions(Request $request, SalesVoucher $sale)
    {
        $this->allow($request, 'sales');

        return response()->json(['businesses' => $this->network->sendOptions($sale)]);
    }

    public function send(Request $request, SalesVoucher $sale)
    {
        $this->allow($request, 'sales');
        $data = $request->validate(['business_id' => ['required', 'integer']]);

        return response()->json(['message' => $this->network->send($sale, (int) $data['business_id'])]);
    }

    private function source(Request $request): array
    {
        $data = $request->validate([
            'source' => ['required', 'array'],
            'source.bill_id' => ['nullable', 'integer'],
            'source.token' => ['nullable', 'string', 'max:2000'],
        ]);

        return array_filter($data['source']);
    }

    /** Sharing bills between businesses is for admins, like creating purchases. */
    private function allow(Request $request, string $page): void
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()) && AppController::canOpen($page), 403);
        abort_unless(NetworkService::ready(), 503, 'Run the database update (php artisan migrate) to use Supplier Bills.');
    }
}
