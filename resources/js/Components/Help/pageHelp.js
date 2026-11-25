// Simple in-app help for every screen. Shown by PageHelp.vue (rendered once in Layout.vue).
// Key = the Inertia "page" value. Where two screens share a page value, "page|Title" is used.
// h(what the page does, tabs [[name, use]], steps, notes)
const h = (what, { tabs = [], steps = [], notes = [] } = {}) => ({ what, tabs, steps, notes });

export const pageHelp = {
    dashboard: h('A quick overview of your business: sales, purchases, stock and pending payments.', {
        steps: ['The top cards show money to collect, money to pay, and today’s sales and purchases. Click a card to see its list.', 'Use the large buttons for daily work: New Sale, New Purchase, Money In, Money Out and more.', 'Check “Pending Actions” every day and click a line to finish it.'],
    }),
    'admin-workspace': h('Daily admin tasks and shortcuts in one place.', { steps: ['Click the card for the task you want to do.'] }),
    'staff-workspace': h('Shortcuts for billing and other daily staff work.', { steps: ['Click the card for the task you want to do.'] }),
    onboarding: h('A checklist for completing the setup of a new company.', { steps: ['Open each pending step in the list and complete it.'] }),
    'company-onboarding': h('Enter the starting details of your company.', { steps: ['Fill in the company details and save.'] }),
    profile: h('Your own login profile.', { steps: ['Update your details or password and save.'] }),

    crm: h('Follow customers from the first enquiry (lead) through to an order.', {
        tabs: [['Dashboard', 'Summary of leads'], ['Leads', 'People who have enquired'], ['Opportunities', 'Deals you expect to win'], ['Kanban', 'Board view by stage'], ['Activities', 'Calls, meetings and follow-ups'], ['Calendar', 'Work arranged by date'], ['Reports', 'CRM reports'], ['Masters', 'Lists such as sources and campaigns']],
        steps: ['Add a new lead in the Leads tab.', 'Record every conversation as an activity.', 'Mark the deal Won when confirmed, otherwise Lost.'],
    }),

    pos: h('The billing counter screen (New Bill).', {
        steps: ['Select the customer.', 'Type a product name or scan its barcode, then check the quantity.', 'Choose the payment: Cash, UPI, Card or Split (fully paid), Part Paid, or Unpaid.', 'Complete the sale and print the bill.'],
        notes: ['Part Paid and Unpaid need a registered customer, not a walk-in customer.'],
    }),
    sales: h('The list of sales invoices already created.', {
        steps: ['Use the filters at the top to narrow by date, customer or payment status.', 'Use Actions to view, print (A4 / 80mm), copy or reverse an invoice.'],
        notes: ['Create a new bill from “New Bill” (POS).'],
    }),
    'sales|Sales Orders': h('Quotations, sales orders and delivery challans (goods sent without a bill).', {
        tabs: [['Dashboard', 'Summary'], ['Quotations', 'Send rates to a customer and convert to an order later'], ['Sales Orders', 'Take an order; approving it reserves stock'], ['Challans', 'Send goods without a bill: sample, gift, self use'], ['Reports', 'Order reports']],
        steps: ['For a challan, fill in the party, date, warehouse, reason, items and quantities.', 'Click “Save & Reduce Stock”.'],
        notes: ['“Save Draft” does not reduce stock.'],
    }),
    'sales-returns': h('Record goods returned by a customer and issue a credit note.', {
        steps: ['Create a new return and select the original invoice.', 'Enter the returned items and quantities.', 'Approve the return. Process a refund if needed and print the credit note.'],
    }),
    'payment-reminders': h('Customers with pending payments, and reminders to them.', { steps: ['Review the pending bills.', 'Send the customer a reminder.', 'When payment arrives, record it with a Receipt voucher.'] }),
    customers: h('Your customers, their outstanding balances and history.', {
        steps: ['Add a customer with “+ New Customer”.', 'Click a customer to see invoices, ledger and outstanding amount.', 'Use “Receive Payment” when money is received.'],
    }),
    'inventory-outward': h('Goods going out: dispatch of billed goods and entries without a bill.', {
        tabs: [['Sale Dispatch', 'Packing and delivery of billed goods; stock is not reduced again'], ['Manual Outward', 'Goods sent without a bill'], ['History', 'Past stock entries']],
        steps: ['To send goods without a bill, click “+ New Outward Entry”.', 'Select the party, date, warehouse and reason (Free sample / Gift / Self use).', 'Add the items and quantities, then click “Save & Reduce Stock”.'],
        notes: ['Do not enter goods here if a sales bill already exists for them.', 'For self use, create a customer named “Self Use”.'],
    }),
    'inventory-reserved': h('Stock held back for orders.', {
        tabs: [['Active', 'Stock currently reserved'], ['Expiring', 'Reservations about to expire'], ['Consumed', 'Reserved stock that has been used'], ['Released', 'Reservations that were cancelled'], ['Ledger', 'Full history']],
        steps: ['Stock is reserved automatically when a sales order is approved.', 'Use Release if it is no longer needed, or Extend to move the expiry date.'],
    }),

    purchases: h('Bills for goods bought from suppliers.', {
        steps: ['Click “New Purchase”, select the supplier, add items and click “Confirm & Post”.', 'For freight, fill in “Transport / Other Charges” and its GST %, if any.', 'Choose the payment: Unpaid, Paid in full or Partly paid. To pay later, click “Pay” on the bill.', 'To correct a bill, use Actions > Edit, then “Save Changes”. The voucher number stays the same.'],
        notes: ['Bills within your state carry CGST and SGST; bills from another state carry IGST.', 'A bill with a payment cannot be edited until that payment is reversed.'],
    }),
    'purchase-returns': h('Return goods to a supplier.', { steps: ['Click “New Return” and select the purchase bill.', 'Enter the items and quantities being returned.', 'Click “Post”. Stock and the supplier balance are updated.'] }),
    suppliers: h('The suppliers you buy from.', { steps: ['Click “New Supplier” and enter the name, mobile number, GSTIN and address.', 'You can edit, delete or restore a supplier.'] }),
    'inventory-inward': h('From requesting goods to receiving them in the warehouse (GRN).', {
        tabs: [['Overview', 'Summary'], ['Requisitions', 'Internal requests for goods'], ['Purchase Orders', 'Orders placed with suppliers'], ['Goods Receipts', 'Record goods that arrive'], ['Reports', 'Reports']],
        steps: ['Create a purchase order and confirm it.', 'When goods arrive, enter the quantities under “Receive Goods / GRN”.', 'Create the purchase bill from it if required.'],
    }),
    'inventory-reorder': h('Products that are low on stock and need to be ordered again.', { steps: ['Select products from the list.', 'Create a purchase order or requisition for them.'] }),
    'inventory-orders': h('Purchase orders for inventory.', { steps: ['Click “+ Create Inventory Order” and add the supplier and items.', 'Submit / approve it, then “Mark Ordered”.', 'When goods arrive, click “Receive Goods”.'] }),

    inventory: h('Control panel for stock movements, adjustments and transfers.', {
        tabs: [['Overview', 'Summary'], ['Movement History', 'Every stock entry'], ['Adjustments', 'Increase or decrease stock'], ['Transfers', 'Move stock between warehouses'], ['Stock Counts', 'Count stock and compare'], ['Locations', 'Storage locations'], ['Reasons', 'Reasons for adjustments'], ['Reports', 'Stock reports']],
        steps: ['Click “+ New Stock Operation” and choose the task.', 'Add the items, then Save Draft or Approve & Post.'],
    }),
    products: h('All products with their prices, GST and barcodes.', {
        steps: ['Click “Add Product” to create a product.', 'To set rates, use Edit > Pricing and enter Cost Price, Selling Price and MRP.', 'For products bought as samples, tick Edit > Basic > “Purchased as sample”.', 'To print labels, tick the products and click “Print Labels”.'],
        notes: ['The “Price missing” filter shows products without a selling price.'],
    }),
    'business-hsn-master': h('HSN/SAC codes and their GST rates.', { steps: ['Click “+ Add HSN/SAC” and pick from the reference list or create one manually.', 'Check the GST rate and save.'], notes: ['Confirm tax rates with your accountant (CA).'] }),
    'opening-stock': h('Record the stock you already had when you started using the app.', { steps: ['Create a “New Voucher” and select the branch and warehouse.', 'Enter products, quantities and cost.', 'Check the details and click “Post”.'], notes: ['A draft does not add stock. You must post it.'] }),
    'inventory-current-stock': h('Current stock of every product.', { steps: ['Find a product using search or filters.', 'Use the row actions for the stock ledger, batch or serial details.', 'Export the list as CSV, Excel or PDF.'] }),
    'inventory-vouchers': h('Stock vouchers: adjustments, transfers and counts.', { steps: ['Click “+ New Stock Operation” and choose the voucher type.', 'Add the items and post.'] }),
    'inventory-batches': h('Goods tracked by batch number and expiry date.', {
        tabs: [['Overview', 'Summary'], ['Batches', 'All batches'], ['Batch Movements', 'Entry history of batches'], ['Expiry', 'Goods expiring soon'], ['Quarantine', 'Goods put on hold'], ['Reports', 'Reports']],
        steps: ['Use “+ New Batch Operation” to work with a batch.', 'Check the Expiry tab regularly.'],
    }),
    'inventory-serials': h('Individual serial / IMEI numbers for each piece.', { steps: ['Add numbers with “Add Serial” or “Bulk Add”.', 'Use Actions to transfer, block, send for repair or mark as damaged.'] }),
    'inventory-barcode-center': h('Create, assign and print barcodes.', {
        steps: ['Click “Assign Barcode”, select a product and enter or generate a barcode.', 'Click “Print Labels” and choose the number of copies and label size.'],
        notes: ['If a product has no barcode, labels printed from Product Master use its SKU as the barcode.'],
    }),
    'inventory-manufacturing': h('Make kits or combos (for example a Dental Kit). Stock of the inside items goes down and stock of the kit goes up.', {
        tabs: [['BOM', 'The kit recipe: what goes inside a kit'], ['Production Orders', 'Orders to make kits and their status'], ['Material Requirements', 'Materials needed for orders'], ['Production History', 'Completed entries'], ['Wastage/Scrap', 'Material that was wasted']],
        steps: ['First create the kit itself as a product in Product Master.', 'Click “New Kit / BOM”, choose the kit product, add the inside items with quantities and click “Save Kit”.', 'Click “Make Kit”, choose the kit, enter how many to make and click “Make Kit Now”.'],
        notes: ['If stock is short, the order is saved. Add stock, then use Production Orders > Actions > Complete/Post.', 'To change a kit, use Actions > Edit.', 'Wastage % is extra material lost while making the kit. Keep it 0 if nothing is lost.'],
    }),

    'inventory-warehouses': h('Your warehouses.', { steps: ['Create a warehouse and select its branch.'] }),
    'inventory-bins': h('Racks and bins inside a warehouse.', { steps: ['Review the list of bins and racks. Use Export CSV to download it.'] }),
    'inventory-godown-balance': h('Stock held in each warehouse.', { steps: ['Review the list. Use Export CSV to download it.'] }),
    'inventory-transfer': h('Move goods from one warehouse to another.', { steps: ['Create a “New Transfer”: from, to and items.', 'Dispatch it, then Receive it when the goods arrive.'] }),
    'inventory-transfer-requests': h('Requests for warehouse transfers.', { steps: ['Review the requests and their status.'] }),
    'inventory-adjustment': h('Increase or decrease stock (damage, loss, sample or count difference).', { steps: ['Click “+ New Stock Operation” and choose an adjustment.', 'Enter the product, quantity and reason.', 'Post it.'], notes: ['Stock changes once the adjustment is posted.'] }),
    'inventory-audit': h('Physically count warehouse stock and compare it with the system.', { steps: ['Create a “New Stock Count”.', 'Enter the counted quantities.', 'Check the differences and approve.'] }),
    'inventory-allocation': h('View batch and serial number allocation.', { steps: ['Review the allocations and their status.'] }),

    accounts: h('Ledger accounts and default accounts used for posting.', { steps: ['Create an account with “New Account”.', 'Check the default accounts and save.'], notes: ['Ask your accountant before changing default accounts.'] }),
    vouchers: h('Money entries: Receipt, Payment, Contra and Journal.', {
        tabs: [['Receipt', 'Money received from a customer'], ['Payment', 'Money paid to a supplier'], ['Contra', 'Cash to bank or bank to cash'], ['Journal', 'Other adjustment entries']],
        steps: ['Choose the voucher type.', 'Enter the party, bill, amount and bank or cash account.', 'Post the voucher.'],
        notes: ['Wrong entries are reversed, not deleted.'],
    }),
    ledgers: h('All transactions of an account, customer or supplier.', { tabs: [['Account Ledger', 'Entries of one account'], ['Customer / Supplier Ledger', 'A party’s transactions'], ['Cash / Bank Book', 'Cash and bank entries'], ['Outstanding', 'Pending amounts by party']], steps: ['Choose the type and name, set the dates and click Apply.'] }),
    expenses: h('Expenses and other income.', {
        tabs: [['Expenses', 'Expense entries'], ['Income', 'Income entries'], ['Categories', 'Types of expense and income'], ['Recurring', 'Repeating expenses'], ['Petty Cash', 'Small cash spending'], ['Bank Import', 'Import a bank statement'], ['Reconciliation', 'Match with the bank'], ['Reports', 'Reports']],
        steps: ['Choose a category, then enter the amount and payment method.', 'Save as draft, or approve and post.'],
    }),
    gst: h('GST summary and return figures.', { tabs: [['Summary', 'GST on sales and purchases'], ['Returns', 'GSTR data']], steps: ['Select the month, click Refresh and check the figures.'], notes: ['Have your accountant (CA) verify the figures before filing.'] }),
    'inventory-gst-returns': h('Data for GST returns.', { steps: ['Select the month, check the data and export it.'], notes: ['Have your accountant (CA) verify the figures before filing.'] }),
    'gst-imports': h('Import and check existing GST files.', { steps: ['Upload the file.', 'Review the data, then approve and commit it.'] }),
    'tally-integration': h('Bring data from Tally and reconcile it.', { steps: ['Add an importer and connect it with a pairing code.', 'Review the records, then import the approved data.'], notes: ['Take a backup before importing.'] }),
    'fixed-assets': h('Company assets (machines, computers, furniture) and depreciation.', {
        tabs: [['Register', 'All assets'], ['Acquisition', 'New purchases'], ['Depreciation', 'Depreciation entries'], ['Assignment / Transfer', 'Who holds each asset'], ['Maintenance / Warranty', 'Repairs and warranty'], ['Verification', 'Physical checks'], ['Reports', 'Reports']],
        steps: ['Add an asset with “Add Asset”.', 'Capitalize it, then run depreciation periodically.'],
    }),

    reports: h('Accounting reports.', {
        tabs: [['Day Book', 'All entries of a day'], ['Trial Balance', 'Balance of every account'], ['Profit and Loss', 'Profit or loss'], ['Balance Sheet', 'Assets and liabilities'], ['Cash Flow', 'Money in and out'], ['Receivables / Payables', 'Amounts to collect and to pay']],
        steps: ['Select a report tab, set the dates and click Apply.'],
    }),
    'inventory-reports': h('Stock reports.', { steps: ['Select a report tab, apply filters and export as CSV, Excel or PDF.'] }),
    'stock-ledger': h('Every stock entry of a product: when it came in and went out.', { steps: ['Choose the product and dates, then click Refresh.'] }),
    'inventory-valuation': h('The value of your stock.', { steps: ['Apply filters, click Refresh and export.'] }),
    'inventory-audit-trail': h('Who changed what in vouchers.', { steps: ['Apply filters and review the entries.'] }),
    acceptance: h('A checklist of features.', { steps: ['Check the status of each feature.'] }),
    'setup-acceptance': h('A checklist of setup items.', { steps: ['Check the status of each setup item.'] }),

    masters: h('Basic lists used by products.', { tabs: [['Categories', 'Product groups'], ['Sub Categories', 'Groups within a category'], ['Brands', 'Product brands'], ['Units', 'Units such as NOS, KG and LTR'], ['HSN/SAC', 'Tax codes and GST rates']], steps: ['Select a tab, add a new record and save.'] }),
    branches: h('Your business branches.', { steps: ['Create a new branch. Branches are used in bills and stock.'] }),
    employees: h('Your employees.', { steps: ['Add an employee and save the details.'] }),
    payroll: h('Attendance and salary.', {
        tabs: [['Employees', 'Employee list'], ['Attendance', 'Attendance entries'], ['Salary', 'Salary structures'], ['Payroll', 'Monthly salary processing'], ['Payslips', 'Salary slips'], ['Loans', 'Advances and loans'], ['Reports', 'Reports']],
        steps: ['Enter attendance.', 'Calculate payroll and approve it.', 'Post it and process the payment.'],
    }),
    users: h('People who use the app and their access.', { steps: ['Click “+ Add User” and enter the name, email and role.'], notes: ['Give each user only the access they need.'] }),
    saas: h('Your plan and subscription details.', { steps: ['View your plan and its status.'] }),
    settings: h('Company settings, logo and bill details.', { steps: ['Change the details and save.'] }),
};

export const helpFor = (page, title) => pageHelp[`${page}|${title}`] || pageHelp[page] || null;
