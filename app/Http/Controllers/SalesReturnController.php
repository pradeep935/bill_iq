<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\SalesReturnVoucherRequest;
use App\Models\SalesReturnVoucher;
use App\Services\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;

class SalesReturnController extends Controller
{
    private SalesReturnService $returns;

    public function __construct(SalesReturnService $returns)
    {
        $this->returns = $returns;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('sales-returns')) {
            return $redirect;
        }

        return Inertia::render('Sales/SalesReturns', [
            'page' => 'sales-returns',
            'title' => 'Sales Returns',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function create()
    {
        return $this->index();
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);
        $paginator = $this->returns->list($request->all());

        return response()->json([
            'returns' => $paginator->getCollection()->map(fn (SalesReturnVoucher $voucher) => $this->returns->present($voucher))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function references()
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response()->json($this->returns->references());
    }

    public function searchSales(Request $request)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response()->json($this->returns->searchSales(trim((string) $request->query('q')), $request->only(['customer_id', 'branch_id', 'warehouse_id'])));
    }

    public function saleItems(int $sale)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response()->json($this->returns->saleItems($sale));
    }

    public function store(SalesReturnVoucherRequest $request)
    {
        $this->authorizeReturnAction();
        $voucher = $this->returns->create($request->validated());

        return response()->json(['message' => 'Sales return saved successfully.', 'return' => $this->returns->present($voucher)], 201);
    }

    public function update(SalesReturnVoucherRequest $request, int $return)
    {
        $this->authorizeReturnAction();
        $voucher = $this->returns->update($this->voucher($return), $request->validated());

        return response()->json(['message' => 'Sales return updated successfully.', 'return' => $this->returns->present($voucher)]);
    }

    public function show(int $return)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response()->json(['return' => $this->returns->present($this->voucher($return))]);
    }

    public function edit(int $return)
    {
        return $this->show($return);
    }

    public function approve(int $return)
    {
        $this->authorizeReturnAction();
        $voucher = $this->returns->post($this->voucher($return), 'approved');

        return response()->json(['message' => 'Sales return posted successfully.', 'return' => $this->returns->present($voucher)]);
    }

    public function cancel(int $return)
    {
        $this->authorizeReturnAction();
        $voucher = $this->returns->cancel($this->voucher($return));

        return response()->json(['message' => 'Sales return cancelled successfully.', 'return' => $this->returns->present($voucher)]);
    }

    public function storeRefund(Request $request, int $return)
    {
        $this->authorizeReturnAction();
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'refund_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $voucher = $this->returns->addRefund($this->voucher($return), $data);

        return response()->json(['message' => 'Refund posted successfully.', 'return' => $this->returns->present($voucher)]);
    }

    public function reverse(OpeningStockReverseRequest $request, int $return)
    {
        $this->authorizeReturnAction();
        $voucher = $this->returns->reverse($this->voucher($return), $request->validated()['remarks']);

        return response()->json(['message' => 'Sales return reversed successfully.', 'return' => $this->returns->present($voucher)]);
    }

    public function export(Request $request)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        $filename = 'sales-returns-' . now()->format('Y-m-d') . '.csv';

        return Response::streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Credit Note Number', 'Return Date', 'Original Invoice', 'Original Invoice Date', 'Customer',
                'GSTIN', 'Branch', 'Warehouse', 'Return Type', 'Taxable Credit', 'CGST Reversal', 'SGST Reversal',
                'IGST Reversal', 'Cess Reversal', 'Total Credit', 'Refund Amount', 'Remaining Credit',
                'Settlement Status', 'Return Status', 'Reason', 'Created By', 'Approved By',
            ]);

            foreach ($this->returns->exportRows($request->all()) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function print(int $return)
    {
        abort_unless(AppController::canOpen('sales-returns'), 403);

        return response($this->returns->printHtml($this->voucher($return)));
    }

    private function voucher(int $id): SalesReturnVoucher
    {
        $query = SalesReturnVoucher::query()
            ->with(['customer', 'sale', 'branch', 'warehouse', 'creator', 'items.product.images', 'items.variant', 'items.batch', 'refunds.method'])
            ->where('id', $id);

        AppController::applyTenantScope($query, 'sales_return_vouchers');

        return $query->firstOrFail();
    }

    private function authorizeReturnAction(): void
    {
        abort_unless(AppController::canOpen('sales-returns') && AppController::roleId() !== 3, 403);
    }
}
