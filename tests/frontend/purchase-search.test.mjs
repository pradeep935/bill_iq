import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ref, reactive, computed } from 'vue';

// Exercise the actual drawer script with API, lifecycle and timer boundaries stubbed.
const script = readFileSync(new URL('../../resources/js/Pages/Purchase/Purchases.vue', import.meta.url), 'utf8')
    .split('<script setup>')[1].split('</script>')[0].replace(/^import .*;\n/gm, '');
function drawer(api) {
    let timer;
    const storage = new Map();
    const setup = new Function('ref', 'reactive', 'computed', 'watch', 'onMounted', 'onBeforeUnmount', 'defineProps', 'PurchaseApi', 'setTimeout', 'clearTimeout', 'sessionStorage', `${script}\nreturn { productSearch, products, searchProducts, addProduct, form, persistDraftForProductCreate, restoreDraftAfterProductCreate, showPurchaseDrawer, productSearchError, searchingProducts, needsInventoryDetails };`);
    const state = setup(ref, reactive, computed, () => {}, () => {}, () => {}, () => {}, api, (fn) => { timer = fn; }, () => { timer = null; }, {
        setItem: (key, value) => storage.set(key, value), getItem: key => storage.get(key), removeItem: key => storage.delete(key),
    });
    return { ...state, flush: () => { const fn = timer; timer = null; return fn?.(); } };
}

test('debounce and stale responses cannot replace latest matches or repopulate after selection', async () => {
    const requests = [];
    const state = drawer({ searchProducts: q => new Promise(resolve => requests.push({ q, resolve })) });
    state.productSearch.value = 'ho'; state.searchProducts();
    state.productSearch.value = 'hon'; state.searchProducts();
    const first = state.flush();
    assert.equal(requests.length, 1);
    state.productSearch.value = 'honey'; state.searchProducts();
    const second = state.flush();
    const honey = { id: 1, name: 'honey 500gm', sku: 'H', purchase_rate: 250 };
    requests[1].resolve([honey]); await second;
    requests[0].resolve([]); await first;
    assert.equal(state.products.value[0].id, 1);
    state.productSearch.value = 'honey 500gm'; state.searchProducts();
    const pending = state.flush();
    state.addProduct(honey);
    requests[2].resolve([honey]); await pending;
    assert.equal(state.products.value.length, 0);
    assert.equal(state.form.items[0].purchase_rate, 250);
    assert.equal(state.form.items[0].product_id, 1);
});

test('failed search is visible and a subsequent search recovers', async () => {
    let fail = true;
    const state = drawer({ searchProducts: async () => { if (fail) throw new Error('offline'); return []; } });
    state.productSearch.value = 'unknown'; state.searchProducts(); await state.flush();
    assert.match(state.productSearchError.value, /failed/);
    assert.equal(state.searchingProducts.value, false);
    fail = false; state.searchProducts(); await state.flush();
    assert.equal(state.productSearchError.value, '');
    assert.equal(state.products.value.length, 0);
});

test('return restores header-only drafts and reopens the drawer', () => {
    const state = drawer({});
    Object.assign(state.form, { supplier_id: 9, branch_id: 2, warehouse_id: 3, remarks: 'Keep me' });
    state.persistDraftForProductCreate();
    Object.assign(state.form, { supplier_id: '', branch_id: '', warehouse_id: '', remarks: '' });
    state.restoreDraftAfterProductCreate();
    assert.equal(state.form.supplier_id, 9);
    assert.equal(state.form.branch_id, 2);
    assert.equal(state.form.warehouse_id, 3);
    assert.equal(state.form.remarks, 'Keep me');
    assert.equal(state.showPurchaseDrawer.value, true);
});


test('inventory details open for tracked products and existing inventory data', () => {
    const state = drawer({});
    assert.equal(state.needsInventoryDetails({ tracking_type: 'none', variants: [] }), false);
    for (const item of [{ tracking_type: 'batch' }, { expiry_required: true }, { variants: [{ id: 1 }] }, { manufacturing_date: '2026-09-01' }, { warehouse_location: 'A1' }]) {
        assert.equal(state.needsInventoryDetails(item), true);
    }
});
