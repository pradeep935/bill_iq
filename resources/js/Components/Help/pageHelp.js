// Simple in-app help for every screen. Shown by PageHelp.vue (rendered once in Layout.vue).
// Key = the Inertia "page" value. Where two screens share a page value, "page|Title" is used.
// h(what the page does, tabs [[name, use]], steps, notes)
const h = (what, { tabs = [], steps = [], notes = [] } = {}) => ({ what, tabs, steps, notes });

export const pageHelp = {
    dashboard: h('Poore business ka ek nazar me hisaab: sales, purchase, stock aur baaki payment.', {
        steps: ['Cards me aaj aur is mahine ke aankde dekhein.', 'Kisi card ya module par click karke uski detail kholein.'],
    }),
    'admin-workspace': h('Admin ke roz ke kaam aur shortcuts ek jagah.', { steps: ['Jo kaam karna ho uske card par click karein.'] }),
    'staff-workspace': h('Staff ke liye billing aur roz ke kaam ke shortcuts.', { steps: ['Jo kaam karna ho uske card par click karein.'] }),
    onboarding: h('Nayi company ka setup poora karne ki checklist.', { steps: ['List me jo step baaki hai use kholkar poora karein.'] }),
    'company-onboarding': h('Company ki shuruaati jankari bharne ka page.', { steps: ['Company ki detail bharein aur save karein.'] }),
    profile: h('Aapki apni login profile.', { steps: ['Apni detail ya password badlein aur save karein.'] }),

    crm: h('Naye grahak (leads) se lekar order tak ka follow-up.', {
        tabs: [['Dashboard', 'Leads ka summary'], ['Leads', 'Poochhtaach karne wale log'], ['Opportunities', 'Jin deals ke banne ki ummeed hai'], ['Kanban', 'Stage ke hisaab se board'], ['Activities', 'Call, meeting aur follow-up'], ['Calendar', 'Tareekh ke hisaab se kaam'], ['Reports', 'CRM reports'], ['Masters', 'Source, campaign jaisi list']],
        steps: ['Leads tab me naya lead jodein.', 'Har baat-cheet ko Activity me likhein.', 'Deal pakki hone par Won, warna Lost karein.'],
    }),

    pos: h('Counter par bill banane ka page (New Bill).', {
        steps: ['Customer chunein.', 'Product ka naam type karein ya barcode scan karein, quantity check karein.', 'Payment chunein: Cash, UPI, Card ya Split (poora paid), Part Paid, ya Unpaid.', 'Sale complete karke bill print karein.'],
        notes: ['Part Paid aur Unpaid ke liye registered customer chahiye, walk-in nahi.'],
    }),
    sales: h('Ban chuke sales invoices ki list.', {
        steps: ['Upar filters se date, customer ya payment status chunein.', 'Actions se invoice dekhein, print (A4 / 80mm) karein, copy karein ya reverse karein.'],
        notes: ['Naya bill “New Bill” (POS) se banta hai.'],
    }),
    'sales|Sales Orders': h('Quotation, sales order aur delivery challan (bina bill maal bhejna).', {
        tabs: [['Dashboard', 'Summary'], ['Quotations', 'Grahak ko rate bhejna; baad me order me badlein'], ['Sales Orders', 'Order lena; approve karne par stock reserve hota hai'], ['Challans', 'Bina bill maal bhejna: sample, gift, self use'], ['Reports', 'Order reports']],
        steps: ['Challan: party, date, warehouse, reason, item aur quantity bharein.', '“Save & Reduce Stock” dabayein.'],
        notes: ['Save Draft se stock kam nahi hota.'],
    }),
    'sales-returns': h('Grahak ka wapas kiya hua maal darj karna aur credit note banana.', {
        steps: ['Naya return banayein aur asli invoice chunein.', 'Wapas aaya item aur quantity bharein.', 'Approve karein; zaroorat ho to refund process karein aur credit note print karein.'],
    }),
    'payment-reminders': h('Jin grahakon ka payment baaki hai unki list aur yaad dilana.', { steps: ['Baaki bill dekhein.', 'Grahak ko reminder bhejein.', 'Payment aane par Receipt voucher banayein.'] }),
    customers: h('Grahakon ki list, unka baaki (outstanding) aur history.', {
        steps: ['“+ New Customer” se grahak jodein.', 'Kisi grahak par click karke invoices, ledger aur outstanding dekhein.', 'Payment aaya ho to “Receive Payment” use karein.'],
    }),
    'inventory-outward': h('Maal bahar bhejne ka hisaab: bill wala dispatch aur bina bill wali entry.', {
        tabs: [['Sale Dispatch', 'Bill ban chuke maal ki packing aur delivery; stock dobara nahi katta'], ['Manual Outward', 'Bina bill bheja gaya maal'], ['History', 'Stock ki purani entries']],
        steps: ['Bina bill maal bhejne ke liye “+ New Outward Entry” dabayein.', 'Party, date, warehouse, reason (Free sample / Gift / Self use) chunein.', 'Item aur quantity bharkar “Save & Reduce Stock” dabayein.'],
        notes: ['Jiska bill ban chuka hai use yahan dobara entry na karein.', 'Self use ke liye “Self Use” naam ka customer bana lein.'],
    }),
    'inventory-reserved': h('Order ke liye roka gaya (reserved) stock.', {
        tabs: [['Active', 'Abhi roka hua stock'], ['Expiring', 'Jiski reservation khatam hone wali hai'], ['Consumed', 'Jo bik/bhej diya gaya'], ['Released', 'Jo chhod diya gaya'], ['Ledger', 'Poori history']],
        steps: ['Sales order approve karne par stock yahan apne aap reserve hota hai.', 'Zaroorat na ho to Release karein, ya date badhane ke liye Extend karein.'],
    }),

    purchases: h('Supplier se kharide maal ke bill.', {
        steps: ['“New Purchase” dabayein, supplier chunein, item jodein, “Confirm & Post” karein.', 'Bhada ho to “Transport / Other Charges” aur uska GST % bharein.', 'Payment chunein: Unpaid, Paid in full ya Partly paid. Baad me us bill par “Pay” dabayein.', 'Galti sudharne ke liye Actions > Edit, phir “Save Changes”. Voucher number wahi rehta hai.'],
        notes: ['Same state me CGST aur SGST, dusre state me IGST lagta hai.', 'Jis bill par payment ho chuka hai, use edit karne se pehle payment reverse karein.'],
    }),
    'purchase-returns': h('Supplier ko maal wapas karna.', { steps: ['“New Return” dabayein aur purchase bill chunein.', 'Wapas jane wala item aur quantity bharein.', '“Post” karein; stock aur supplier ka hisaab theek ho jata hai.'] }),
    suppliers: h('Jinse aap maal kharidte hain unki list.', { steps: ['“New Supplier” se naam, mobile, GSTIN aur address bharein.', 'Supplier ko Edit, Delete ya Restore kar sakte hain.'] }),
    'inventory-inward': h('Maal mangwane se lekar godown me aane tak ka kaam (GRN).', {
        tabs: [['Overview', 'Summary'], ['Requisitions', 'Andar se maal ki maang'], ['Purchase Orders', 'Supplier ko order'], ['Goods Receipts', 'Maal aane ki entry'], ['Reports', 'Reports']],
        steps: ['Purchase Order banakar confirm karein.', 'Maal aane par “Receive Goods / GRN” se quantity bharein.', 'Zaroorat ho to usi se purchase bill banayein.'],
    }),
    'inventory-reorder': h('Kam stock wale products ki list, jinhe dobara mangwana hai.', { steps: ['List me products chunein.', 'Unka Purchase Order ya Requisition banayein.'] }),
    'inventory-orders': h('Inventory ke liye purchase orders.', { steps: ['“+ Create Inventory Order” se supplier aur items bharein.', 'Submit / Approve karein, phir “Mark Ordered”.', 'Maal aane par “Receive Goods” karein.'] }),

    inventory: h('Stock ki movement, adjustment aur transfer ka control panel.', {
        tabs: [['Overview', 'Summary'], ['Movement History', 'Har stock entry'], ['Adjustments', 'Stock ghatana-badhana'], ['Transfers', 'Ek godown se dusre me'], ['Stock Counts', 'Ginti aur milan'], ['Locations', 'Rakhne ki jagah'], ['Reasons', 'Adjustment ke karan'], ['Reports', 'Stock reports']],
        steps: ['“+ New Stock Operation” se kaam chunein.', 'Items bharkar Save Draft ya Approve & Post karein.'],
    }),
    products: h('Saare products, unke rate, GST aur barcode.', {
        steps: ['“Add Product” se naya product banayein.', 'Rate bharne ke liye Edit > Pricing me Cost, Selling Price aur MRP daalein.', 'Sample ke taur par kharide product me Edit > Basic > “Purchased as sample” tick karein.', 'Label ke liye products tick karke “Print Labels” dabayein.'],
        notes: ['Filter “Price missing” se bina rate wale products milte hain.'],
    }),
    'business-hsn-master': h('HSN/SAC code aur unke GST rate.', { steps: ['“+ Add HSN/SAC” se reference list me se chunein ya manual banayein.', 'GST rate check karke save karein.'], notes: ['Rate ko apne CA se confirm karein.'] }),
    'opening-stock': h('App shuru karte waqt godown me pehle se rakha stock darj karna.', { steps: ['“New Voucher” banayein, branch aur godown chunein.', 'Products, quantity aur cost bharein.', 'Check karke “Post” karein.'], notes: ['Draft se stock nahi badhta, Post karna zaroori hai.'] }),
    'inventory-current-stock': h('Abhi kis product ka kitna stock hai.', { steps: ['Search ya filter se product dhoondhein.', 'Row ke Actions se Stock Ledger, batch ya serial detail dekhein.', 'CSV / Excel / PDF se list nikaalein.'] }),
    'inventory-vouchers': h('Stock se jude vouchers: adjustment, transfer, ginti.', { steps: ['“+ New Stock Operation” se voucher ka type chunein.', 'Items bharein aur Post karein.'] }),
    'inventory-batches': h('Batch number aur expiry date wale maal ka hisaab.', {
        tabs: [['Overview', 'Summary'], ['Batches', 'Sab batch'], ['Batch Movements', 'Batch ki entry history'], ['Expiry', 'Jaldi expire hone wala maal'], ['Quarantine', 'Roka gaya maal'], ['Reports', 'Reports']],
        steps: ['“+ New Batch Operation” se batch ka kaam karein.', 'Expiry tab roz check karein.'],
    }),
    'inventory-serials': h('Har piece ka alag serial / IMEI number.', { steps: ['“Add Serial” ya “Bulk Add” se number daalein.', 'Actions se transfer, block, repair ya damaged mark karein.'] }),
    'inventory-barcode-center': h('Barcode banana, assign karna aur label print karna.', {
        steps: ['“Assign Barcode” se product chunkar barcode daalein ya Generate karein.', '“Print Labels” se copies aur size chunkar print karein.'],
        notes: ['Barcode set na ho to Product Master ke label me SKU barcode ban jata hai.'],
    }),
    'inventory-manufacturing': h('Kit / combo banana (jaise Dental Kit): items ka stock ghatta hai, kit ka badhta hai.', {
        tabs: [['BOM', 'Kit ki recipe: kit me kya-kya jata hai'], ['Production Orders', 'Banane ke order aur unka status'], ['Material Requirements', 'Order ke liye maal ki zaroorat'], ['Production History', 'Ban chuki entries'], ['Wastage/Scrap', 'Barbaad hua maal']],
        steps: ['Pehle kit ko Product Master me product ke roop me banayein.', '“New Kit / BOM” me kit product, andar ke items aur quantity bharkar “Save Kit” karein.', '“Make Kit” me kit aur kitni banani hai likhkar “Make Kit Now” dabayein.'],
        notes: ['Stock kam ho to order save rehta hai; stock aane par Production Orders > Actions > Complete/Post.', 'Kit badalne ke liye Actions > Edit.', 'Wastage % = banate waqt barbaad hone wala extra maal; na ho to 0.'],
    }),

    'inventory-warehouses': h('Godown (warehouse) ki list.', { steps: ['Naya godown banayein aur uski branch chunein.'] }),
    'inventory-bins': h('Godown ke andar rack / bin.', { steps: ['Bin aur rack ki list dekhein; Export CSV se nikaalein.'] }),
    'inventory-godown-balance': h('Har godown me kitna stock hai.', { steps: ['List dekhein; Export CSV se nikaalein.'] }),
    'inventory-transfer': h('Ek godown se dusre godown me maal bhejna.', { steps: ['“New Transfer” banayein: kahan se, kahan ko, items.', 'Dispatch karein, aur maal pahunchne par Receive karein.'] }),
    'inventory-transfer-requests': h('Godown transfer ki maang (requests).', { steps: ['Requests ki list aur status dekhein.'] }),
    'inventory-adjustment': h('Stock ko ghatana ya badhana (damage, loss, sample, ginti ka farak).', { steps: ['“+ New Stock Operation” se adjustment chunein.', 'Product, quantity aur karan bharein.', 'Post karein.'], notes: ['Post karne ke baad stock badal jata hai.'] }),
    'inventory-audit': h('Godown ke maal ki ginti aur system se milan.', { steps: ['“New Stock Count” banayein.', 'Gini hui quantity bharein.', 'Farak check karke approve karein.'] }),
    'inventory-allocation': h('Batch / serial ka allocation dekhna.', { steps: ['List me allocation aur status dekhein.'] }),

    accounts: h('Accounting ke khaate (ledger accounts) aur default khaate.', { steps: ['“New Account” se khaata banayein.', 'Default accounts check karke save karein.'], notes: ['Default khaate badalne se pehle accountant se poochein.'] }),
    vouchers: h('Paise ki entry: Receipt, Payment, Contra aur Journal.', {
        tabs: [['Receipt', 'Grahak se paisa aaya'], ['Payment', 'Supplier ko paisa diya'], ['Contra', 'Cash se bank ya bank se cash'], ['Journal', 'Baaki adjustment entry']],
        steps: ['Type chunein.', 'Party, bill, amount aur bank/cash bharein.', 'Post karein.'],
        notes: ['Galat entry ko delete nahi, Reverse karte hain.'],
    }),
    ledgers: h('Kisi bhi khaate, grahak ya supplier ka pura lena-dena.', { tabs: [['Account Ledger', 'Ek khaate ki entries'], ['Customer / Supplier Ledger', 'Party ka hisaab'], ['Cash / Bank Book', 'Cash aur bank ki entries'], ['Outstanding', 'Kiska kitna baaki']], steps: ['Type aur naam chunein, date lagakar Apply karein.'] }),
    expenses: h('Kharche aur doosri income.', {
        tabs: [['Expenses', 'Kharche'], ['Income', 'Aamdani'], ['Categories', 'Kharche ke prakar'], ['Recurring', 'Har mahine wale kharche'], ['Petty Cash', 'Chhota nakad'], ['Bank Import', 'Bank statement daalna'], ['Reconciliation', 'Bank se milan'], ['Reports', 'Reports']],
        steps: ['Category chunkar amount aur payment ka tareeka bharein.', 'Draft save karein ya approve karke post karein.'],
    }),
    gst: h('GST ka summary aur returns ke aankde.', { tabs: [['Summary', 'Sales/Purchase GST'], ['Returns', 'GSTR data']], steps: ['Mahina chunein, Refresh karein, aankde jaanchein.'], notes: ['File karne se pehle CA se milan karayein.'] }),
    'inventory-gst-returns': h('GST returns ke liye data.', { steps: ['Mahina chunein aur data jaanch kar export karein.'], notes: ['File karne se pehle CA se milan karayein.'] }),
    'gst-imports': h('GST ki purani files import karna aur jaanchna.', { steps: ['File upload karein.', 'Data check karke approve aur commit karein.'] }),
    'tally-integration': h('Tally se data lana aur milan karna.', { steps: ['Importer jodkar pairing code se connect karein.', 'Records review karein, phir approved data import karein.'], notes: ['Import se pehle backup lein.'] }),
    'fixed-assets': h('Company ki sampatti (machine, computer, furniture) aur depreciation.', {
        tabs: [['Register', 'Sab assets'], ['Acquisition', 'Nayi kharid'], ['Depreciation', 'Ghisaai ka hisaab'], ['Assignment / Transfer', 'Kiske paas hai'], ['Maintenance / Warranty', 'Repair aur warranty'], ['Verification', 'Jaanch'], ['Reports', 'Reports']],
        steps: ['“Add Asset” se asset jodein.', 'Capitalize karein, phir samay par Depreciation chalayein.'],
    }),

    reports: h('Accounting reports.', {
        tabs: [['Day Book', 'Din ki sab entries'], ['Trial Balance', 'Sab khaaton ka balance'], ['Profit and Loss', 'Munafa / nuksaan'], ['Balance Sheet', 'Sampatti aur dena'], ['Cash Flow', 'Paisa aana-jaana'], ['Receivables / Payables', 'Lena aur dena']],
        steps: ['Report ka tab chunein, date lagakar Apply karein.'],
    }),
    'inventory-reports': h('Stock se judi reports.', { steps: ['Report ka tab chunein, filter lagayein, CSV / Excel / PDF nikaalein.'] }),
    'stock-ledger': h('Har product ki stock entry: kab aaya, kab gaya.', { steps: ['Product aur date chunein, Refresh karein.'] }),
    'inventory-valuation': h('Stock ki keemat.', { steps: ['Filter lagayein, Refresh karein aur export karein.'] }),
    'inventory-audit-trail': h('Voucher me kisne kya badlav kiya.', { steps: ['Filter lagakar entries dekhein.'] }),
    acceptance: h('Features ki jaanch (acceptance) list.', { steps: ['Har feature ka status dekhein.'] }),
    'setup-acceptance': h('Setup ki jaanch list.', { steps: ['Har setup item ka status dekhein.'] }),

    masters: h('Product ke liye basic lists.', { tabs: [['Categories', 'Product ke group'], ['Sub Categories', 'Group ke andar group'], ['Brands', 'Company/brand'], ['Units', 'NOS, KG, LTR jaisi ikai'], ['HSN/SAC', 'Tax code aur GST rate']], steps: ['Tab chunein, naya record jodein aur save karein.'] }),
    branches: h('Business ki branches.', { steps: ['Nayi branch banayein; yahi bills aur stock me use hoti hai.'] }),
    employees: h('Karmchariyon ki list.', { steps: ['Employee jodkar detail save karein.'] }),
    payroll: h('Attendance aur salary.', {
        tabs: [['Employees', 'Karmchari'], ['Attendance', 'Haazri'], ['Salary', 'Salary structure'], ['Payroll', 'Mahine ki salary banana'], ['Payslips', 'Salary parchi'], ['Loans', 'Advance aur loan'], ['Reports', 'Reports']],
        steps: ['Haazri bharein.', 'Payroll calculate karke approve karein.', 'Post karke payment process karein.'],
    }),
    users: h('App use karne wale log aur unka access.', { steps: ['“+ Add User” se naam, email aur role chunein.'], notes: ['Har user ko zaroorat jitna hi access dein.'] }),
    saas: h('Plan aur subscription ki jankari.', { steps: ['Apna plan aur status dekhein.'] }),
    settings: h('Company ki settings, logo aur bill ki jankari.', { steps: ['Detail badlein aur save karein.'] }),
};

export const helpFor = (page, title) => pageHelp[`${page}|${title}`] || pageHelp[page] || null;
