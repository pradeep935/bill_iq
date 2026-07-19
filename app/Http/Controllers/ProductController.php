<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\HsnMaster;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index(){
        return Inertia::render('Product/Index', [
            'page' => 'products',
            'title' => 'Products & Barcode',
            'role_id' => Auth::user()->role_id,
        ]);
    }

    public function products()
{
    $businessId = AppController::businessId();

    $products = Product::where('business_id', $businessId)
        ->orderBy('id', 'desc')
        ->get();

    return response()->json([
        'products' => $products,
    ]);
}

    public function save(Request $request)
    {
        $businessId = AppController::businessId();

        $productId = (int) $request->input('id', 0);

        $request->validate([
            'name' => 'required|string|max:255',
            'product_type' => 'required|in:goods,service',
            'category' => 'nullable|string|max:150',
            'brand' => 'nullable|string|max:150',
            'variant' => 'nullable|string|max:150',
            'unit' => 'required|string|max:30',

            'hsn_master_id' => 'nullable|integer|exists:hsn_masters,id',
            'hsn_code' => 'required|string|max:20',
            'taxability' => 'required|in:taxable,exempt,nil_rated,non_gst',
            'gst_rate' => 'required|numeric|min:0|max:100',
            'cess_rate' => 'nullable|numeric|min:0|max:100',
            'reverse_charge' => 'required|in:yes,no',
            'invoice_description' => 'nullable|string|max:500',

            'selling_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'mrp' => 'nullable|numeric|min:0',
            'opening_stock' => 'nullable|numeric|min:0',
            'minimum_stock' => 'nullable|numeric|min:0',
            'reorder_stock' => 'nullable|numeric|min:0',

            'tracking_type' => 'required|in:none,batch,batch_expiry,serial,imei',

            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                ->where('business_id', $businessId)
                ->ignore($productId),
            ],

            'primary_barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'primary_barcode')
                ->where('business_id', $businessId)
                ->ignore($productId),
            ],

            'extra_barcodes' => 'nullable|string',
            'status' => 'required|in:active,inactive',
        ]);

        if (
            $request->filled('mrp') &&
            (float) $request->selling_price > (float) $request->mrp
        ) {
            return response()->json([
                'message' => 'Selling price cannot be greater than MRP.',
                'errors' => [
                    'selling_price' => [
                        'Selling price cannot be greater than MRP.',
                    ],
                ],
            ], 422);
        }

        if ($productId > 0) {
            $product = Product::where('business_id', $businessId)
            ->where('id', $productId)
            ->firstOrFail();

            $message = 'Product updated successfully.';
        } else {
            $product = new Product();
            $product->business_id = $businessId;

            $message = 'Product saved successfully.';
        }

        $product->name = $request->name;
        $product->product_type = $request->product_type;
        $product->category = $request->category;
        $product->brand = $request->brand;
        $product->variant = $request->variant;
        $product->unit = $request->unit;

        $product->hsn_master_id = $request->hsn_master_id;
        $product->hsn_code = $request->hsn_code;
        $product->taxability = $request->taxability;
        $product->gst_rate = $request->gst_rate;
        $product->cess_rate = $request->cess_rate ?: 0;
        $product->reverse_charge = $request->reverse_charge;
        $product->invoice_description = $request->invoice_description;

        $product->selling_price = $request->selling_price;
        $product->cost_price = $request->cost_price ?: 0;
        $product->mrp = $request->mrp;
        $product->opening_stock = $request->opening_stock ?: 0;
        $product->minimum_stock = $request->minimum_stock ?: 0;
        $product->reorder_stock = $request->reorder_stock ?: 0;

        $product->tracking_type = $request->tracking_type;
        $product->sku = $request->sku;
        $product->primary_barcode = $request->primary_barcode;
        $product->extra_barcodes = $request->extra_barcodes;
        $product->status = $request->status;

        $product->save();

        return response()->json([
            'message' => $message,
            'product' => $product,
        ], $productId > 0 ? 200 : 201);
    }

    public function destroy(Product $product)
    {
        $businessId = AppController::businessId();

        abort_unless(
            (int) $product->business_id === (int) $businessId,
            403
        );

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    public function hsnSearch(Request $request)
    {
        $search = trim((string) $request->query('q'));

        $hsnMaster = HsnMaster::where('status', 'active')
        ->when($search !== '', function ($query) use ($search) {
            $query->where(function ($innerQuery) use ($search) {
                $innerQuery
                ->where('hsn_code', 'like', $search . '%')
                ->orWhere(
                    'description',
                    'like',
                    '%' . $search . '%'
                );
            });
        })
        ->orderBy('hsn_code')
        ->limit(20)
        ->get([
            'id',
            'hsn_code',
            'description',
            'gst_rate',
            'cess_rate',
            'effective_from',
            'notification_number',
            'source_reference',
        ]);

        return response()->json($hsnMaster);
    }
}