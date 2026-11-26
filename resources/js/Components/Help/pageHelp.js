import { buttonGuides } from './buttonGuides.js';

// Simple in-app help for every screen. Shown by PageHelp.vue (rendered once in Layout.vue).
// Key = the Inertia "page" value. Where two screens share a page value, "page|Title" is used.
// h(what the page does, tabs [[name, use]], steps, notes, faq [[question, answer]],
//   buttons [[group, [[name, what it does, when to use it]]]])
const h = (what, { tabs = [], steps = [], notes = [], faq = [], buttons = [] } = {}) => ({ what, tabs, steps, notes, faq, buttons });

export const pageHelp = {
    dashboard: h('The first screen of your business: money to collect and pay, today’s work, pending jobs, money and stock. Every card and line can be clicked.', {
        steps: ['In the morning, look at the four top cards.', 'Open each line under “Pending Actions” and finish it.', 'During the day, use the buttons under “What do you want to do?”.', 'In the evening, check “Today’s Sales”, “Recent Sales” and the cash balance.'],
        buttons: [
            ['Top cards', [
                ['To Collect', 'Total amount customers still have to pay on posted invoices, with the number of unpaid invoices. Click to see those invoices.', 'To decide whom to remind for payment.'],
                ['To Pay', 'Total amount you still have to pay on posted purchase bills. Click to see those bills.', 'To plan supplier payments.'],
                ['Today’s Sales', 'Total and number of invoices dated today. Click to see them.', 'To check today’s billing.'],
                ['Today’s Purchases', 'Total and number of purchase bills dated today. Click to see them.', 'To check what was bought today.'],
            ]],
            ['What do you want to do?', [
                ['New Sale', 'Opens the billing screen (POS).', 'Whenever you sell goods: add items, take payment, click “Complete Sale” and print.'],
                ['New Purchase', 'Opens a blank purchase form.', 'When goods arrive from a supplier. “Confirm & Post” adds the stock and the amount payable.'],
                ['Money In', 'Opens a Receipt voucher.', 'When a customer pays an old or credit bill. Choose the customer, the bill, amount and cash or bank.'],
                ['Money Out', 'Opens a Payment voucher.', 'When you pay a supplier for an old or credit bill.'],
                ['Add Expense', 'Opens Expenses.', 'For rent, electricity, salary, tea, transport and other spending that is not goods for sale.'],
                ['Add Product', 'Opens a blank product form.', 'Before billing a new item. Fill name, unit, GST %, purchase and selling rate.'],
                ['Add Customer', 'Opens a blank customer form.', 'For credit sales, GST bills and sending bills on WhatsApp.'],
                ['Add Supplier', 'Opens a blank supplier form.', 'Before entering the first purchase from a new supplier.'],
            ]],
            ['Pending Actions (click a line to open its list)', [
                ['Supplier payments pending', 'Number and amount of purchase bills not fully paid.', 'Pay them with “Pay” on the bill or with Money Out.'],
                ['Customer payments to collect', 'Number and amount of invoices not fully paid.', 'Send a reminder, and record the money with Money In when it comes.'],
                ['Products with minus stock', 'Products sold in more quantity than was entered.', 'Enter the missing purchase or opening stock, or correct it with Stock Adjustment.'],
                ['Draft purchases not posted', 'Purchase bills saved but not posted.', 'Open and post them. A draft does not add stock.'],
                ['Draft sales invoices', 'Invoices saved but not completed.', 'Use Actions > Edit or Post, or cancel the ones not needed.'],
                ['Products without selling price', 'Products whose selling rate is blank.', 'Set the rate so that billing fills it automatically.'],
            ]],
            ['Recent Sales', [
                ['Customer name', 'Shows that invoice.', 'To check what was sold in it.'],
                ['Print', 'Opens the invoice for printing.', 'To give or re-print a bill.'],
                ['View all', 'Opens all sales invoices.', 'To search, filter, edit or export invoices.'],
            ]],
            ['Sales and Purchases chart', [
                ['Blue and orange bars', 'Sales (blue) and purchases (orange) of each of the last six months. Move the mouse on a bar to see the amount.', 'To see whether business is rising or falling. It is not a profit report.'],
            ]],
            ['Money', [
                ['Cash in hand', 'Cash balance as per your entries. Click to open Ledgers.', 'Match it with the cash in the drawer. A difference usually means a missing entry.'],
                ['Bank balance', 'Bank balance as per your entries. Click to open Ledgers.', 'Match it with the bank statement.'],
                ['Today’s profit', 'Today’s sales without GST minus the cost of the goods sold. Expenses are not subtracted.', 'A quick estimate only. Use Business Reports for real profit.'],
                ['GST to pay / GST credit', 'GST on all your sales minus GST on all your purchases till date. Returns and GST already deposited are not subtracted. Click to open GST.', 'For a rough idea only. Confirm the figure with your accountant (CA) before paying or filing.'],
            ]],
            ['Stock', [
                ['Open stock', 'Opens Current Stock.', 'To see the quantity of every product.'],
                ['Stock value', 'Quantity in hand multiplied by its average cost.', 'To know how much money is held in goods. Minus stock lowers this figure.'],
                ['Products in stock', 'Products whose quantity is more than zero.', 'To see what you can sell now.'],
                ['Out of stock / minus', 'Products with zero or minus quantity. Click to see the list.', 'To order again or correct wrong stock.'],
                ['Low stock', 'Products at or below their reorder level.', 'To order before they finish. Set the reorder level in the product.'],
                ['Product names in the lists', 'Opens that product.', 'To check or correct its details.'],
                ['Expiring in 30 days', 'Batches that will expire soon (shown only when there are any).', 'To sell or return them first.'],
            ]],
            ['Your business', [
                ['Products', 'Number of products. Click to open Product Master.', 'To add or correct items.'],
                ['Customers', 'Number of customers. Click to open Customers.', 'To see their bills and balances.'],
                ['Suppliers', 'Number of suppliers. Click to open Suppliers.', 'To add or correct suppliers.'],
            ]],
            ['Top bar (on most screens)', [
                ['Search box', 'Finds invoices, customers and products as you type.', 'To open something quickly without going through menus.'],
                ['New Bill', 'Opens the billing screen.', 'To start a sale from any screen.'],
                ['Voucher', 'Opens Receipt, Payment, Contra and Journal entries.', 'For money entries.'],
                ['Reports', 'Opens Business Reports.', 'For profit and loss, balance sheet, day book and more.'],
                ['Bell', 'Notifications.', 'There are no notifications yet.'],
                ['? (question mark)', 'Opens the guide of the current screen.', 'Whenever you are not sure what to do. “All guides” lists every screen.'],
                ['Your name', 'Opens your profile.', 'To see your login details.'],
            ]],
            ['Left menu', [
                ['Box with HOME, SALES… (below the business name)', 'Changes the menu section.', 'Choose Sales, Purchase, Inventory, Accounting, Reports or Setup.'],
                ['Business Dashboard', 'This screen.', 'For the daily overview.'],
                ['Admin Workspace', 'Business setup, staff, users and settings.', 'For setup work.'],
                ['Staff Workspace', 'Daily work screen with shortcuts and pending tasks.', 'For billing staff.'],
                ['Onboarding', 'The first-time setup checklist.', 'Until every required step is complete.'],
                ['Logout', 'Signs you out.', 'Always log out on a shared computer.'],
            ]],
            ['Bottom corner', [
                ['Ask BillIQ', 'A chat assistant that answers questions about using BillIQ.', 'When you want to ask in your own words.'],
            ]],
        ],
        faq: [
            ['Why is a number different from what I expect?', 'Only posted (completed) bills are counted. Drafts, cancelled and reversed bills are left out.'],
            ['Why do I not see all these cards?', 'Each login sees only what its role allows.'],
            ['Why is stock minus?', 'More was sold than was entered. Add the purchase or opening stock.'],
        ],
    }),
    'admin-workspace': h('Business setup in one place: what is ready, what is pending and shortcuts to every admin task.', {
        tabs: [['Overview', 'Branches, warehouses, products and masters'], ['Staff', 'Employee records and who is missing a login, branch or role'], ['Setup Steps', 'The checklist for starting daily billing'], ['Users', 'Add logins, choose Admin or Staff, activate or deactivate'], ['Settings', 'Business profile, logo, bank details and bill printing']],
        steps: ['Open “Needs your attention” and click “Fix now” on each line.', 'Use the buttons under “What do you want to do?” for daily admin work.', 'Click any card under “Your setup” to open that list.', 'Click “Refresh” after adding something to update the counts.'],
        notes: ['Items under “Optional” can be left empty.'],
        faq: [
            ['How do I give my staff a login?', 'Open the Users tab, click “+ Add User”, enter name, email, role and a password of at least 8 characters, and save. Share the email and password with them.'],
            ['What is the difference between the roles?', 'Admin opens everything. Staff can do billing, work with customers and view stock. Sales opens only sales work. Purchase opens only purchase work.'],
            ['A staff member forgot the password.', 'Users tab > Edit > enter a new password > Save.'],
            ['Someone left the job.', 'Users tab > Deactivate. Their old bills stay in the records.'],
            ['How do I change the name, GSTIN or logo on the bill?', 'Open the Settings tab, change the details and click “Save Settings”.'],
        ],
    }),
    'staff-workspace': h('The daily work screen: billing and stock shortcuts, pending work and alerts for your branch.', {
        steps: ['Click a button under “What do you want to do?”.', 'Finish the lines under “My Pending Work”.', 'Check “Inventory Alerts” for products running short.'],
        notes: ['If it says branch or warehouse is not assigned, ask your admin.'],
        faq: [['I cannot see a menu.', 'Your role does not include it. Ask your admin.']],
    }),
    onboarding: h('The setup checklist of a company. Finish the required steps once before daily billing.', {
        steps: ['Start with the blue “Do this next” box.', 'Open each line under “To do” and complete it.', 'Click “Refresh” to see the new progress.'],
        notes: ['“Optional” steps do not affect the progress percentage.', 'A step that is “Blocked” opens after the earlier step is finished.'],
    }),
    'company-onboarding': h('Enter the starting details of your company.', { steps: ['Fill in the company details and save.'] }),
    profile: h('Your own login profile.', { steps: ['Update your details or password and save.'] }),

    crm: h('Follow customers from the first enquiry (lead) through to an order.', {
        tabs: [['Dashboard', 'Summary of leads'], ['Leads', 'People who have enquired'], ['Opportunities', 'Deals you expect to win'], ['Kanban', 'Board view by stage'], ['Activities', 'Calls, meetings and follow-ups'], ['Calendar', 'Work arranged by date'], ['Reports', 'CRM reports'], ['Masters', 'Lists such as sources and campaigns']],
        steps: ['Add a new lead in the Leads tab.', 'Record every conversation as an activity.', 'Mark the deal Won when confirmed, otherwise Lost.'],
    }),

    pos: h('The billing counter screen (New Bill).', {
        steps: ['Select the customer, or leave Walk-in Customer. You can search by mobile number and add a new customer there.', 'Choose the bill type: B2C Retail, B2B Tax Invoice or Bill of Supply.', 'Type a product name or scan its barcode, then check quantity and rate.', 'Add a discount if needed.', 'Choose the payment: Cash, UPI, Card or Split (fully paid), Part Paid, or Unpaid.', 'Tick Print 80mm, Print A4 or Share WhatsApp if wanted, then click “Complete Sale”.'],
        notes: ['Part Paid and Unpaid need a registered customer, not a walk-in customer.', 'B2B Tax Invoice needs the customer GSTIN.', '“Hold Bill” keeps a bill to finish later; open it again from “Held Bills”.', 'Keys: F1 new bill, F2 customer, F3 product search, F4 hold, F5 payment, F6 print.'],
        faq: [['It says insufficient stock.', 'Enter the purchase bill or opening stock of that product first.'], ['I made a mistake in a completed bill.', 'Sales Invoices > Actions > Edit.']],
    }),
    sales: h('All sales invoices: view, print, correct and share them.', {
        steps: ['Use the filters to narrow by date, customer, status or payment, then click Apply.', 'Use Actions to view, print (A4 / 80mm), copy, edit or reverse an invoice.', 'To correct a bill: Actions > Edit, change what is wrong and click “Save Changes”. The invoice number stays the same.', 'To send the bill to a customer who uses BillIQ: Actions > “Send on BillIQ”.'],
        notes: ['Create a new bill from “New Bill” (POS).', 'Editing corrects stock and accounts automatically. The earlier version is kept under Status > Reversed with -R1 after the number.', 'Only an admin can edit a reversed or cancelled invoice, or one with a receipt voucher.', 'If a receipt is recorded, the customer cannot be changed and the total cannot go below the amount received.', 'If a sales return exists, cancel or reverse the return before editing.', 'Some older or imported bills total a little differently when recalculated. The screen warns you before saving.'],
        faq: [['Difference between Edit and Reverse?', 'Edit corrects the same invoice. Reverse cancels its effect on stock and accounts.'], ['It says insufficient stock while editing.', 'You raised a quantity above the available stock. Add stock first or lower the quantity.']],
    }),
    'sales|Sales Orders': h('Quotations, sales orders and delivery challans (goods sent without a bill).', {
        tabs: [['Dashboard', 'Summary'], ['Quotations', 'Send rates to a customer and convert to an order later'], ['Sales Orders', 'Take an order; approving it reserves stock'], ['Challans', 'Send goods without a bill: sample, gift, self use'], ['Reports', 'Order reports']],
        steps: ['For a challan, fill in the party, date, warehouse, reason, items and quantities.', 'Click “Save & Reduce Stock”.', 'To charge for those goods, click “Create Bill” on the challan, check the rates in the draft invoice and post it. The stock is not reduced again.'],
        notes: ['“Save Draft” does not reduce stock.', 'Always bill challan goods with “Create Bill”. If you bill the same goods separately, the app warns you.', 'For goods given away, write “Sample”, “Gift” or “Self use” in the reason; the app then does not expect a bill.', 'Reversing the bill leaves the goods out of stock (they are with the customer) and lets you bill the challan again.'],
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
        notes: ['Do not enter goods here if a sales bill already exists for them; the app warns you.', 'To bill goods sent from here, use Sales Orders & Challans > Challans > “Create Bill”.', 'For self use, create a customer named “Self Use” and choose the reason “Self use”.'],
    }),
    'inventory-reserved': h('Stock held back for orders.', {
        tabs: [['Active', 'Stock currently reserved'], ['Expiring', 'Reservations about to expire'], ['Consumed', 'Reserved stock that has been used'], ['Released', 'Reservations that were cancelled'], ['Ledger', 'Full history']],
        steps: ['Stock is reserved automatically when a sales order is approved.', 'Use Release if it is no longer needed, or Extend to move the expiry date.'],
    }),

    purchases: h('Bills for goods bought from suppliers.', {
        steps: ['Click “New Purchase”, select the supplier, add items and click “Confirm & Post”.', 'For freight, fill in “Transport / Other Charges” and its GST %, if any.', 'Choose the payment: Unpaid, Paid in full or Partly paid. To pay later, click “Pay” on the bill.', 'To correct a bill: Actions > Edit, then “Save Changes”. The voucher number stays the same.', 'If your supplier uses BillIQ, click “From Supplier Bill” and paste the bill link.'],
        notes: ['Posting a bill adds stock. For goods already received through Stock Inward / GRN, make the bill from there (“Create Purchase Bill”) so that they are counted once.', 'Bills within your state carry CGST and SGST; bills from another state carry IGST.', 'Only an admin can edit a reversed or cancelled bill, or one with a payment voucher.', 'If a payment voucher is recorded, the supplier cannot be changed and the total cannot go below the amount paid.', 'If a purchase return exists, cancel or reverse the return before editing.', 'The earlier version of an edited bill is kept under Status > Reversed with -R1 after the number.'],
        faq: [['It says the bill is already entered again.', 'The same supplier bill exists under another voucher number. Edit that one.']],
    }),
    'supplier-bills': h('Get bills from suppliers who use BillIQ and add them to purchases without typing.', { steps: ['Open “Connections”, search the supplier by full mobile number or GSTIN and click “Send Request”. They must accept it once.', 'The supplier opens their sales invoice and clicks Actions > “Send on BillIQ”.', 'The bill appears under “Received Bills”. Click “Add to Purchase”.', 'Match each item with your product, or add it as a new product.', 'Check the purchase form and click “Confirm & Post”. Stock changes only then.', 'Without a connection, paste the bill link the supplier sent on WhatsApp.'] }),
    'purchase-returns': h('Return goods to a supplier.', { steps: ['Click “New Return” and select the purchase bill.', 'Enter the items and quantities being returned.', 'Click “Post”. Stock and the supplier balance are updated.'] }),
    suppliers: h('The suppliers you buy from.', { steps: ['Click “New Supplier” and enter the name, mobile number, GSTIN and address.', 'You can edit, delete or restore a supplier.'] }),
    'inventory-inward': h('From requesting goods to receiving them in the warehouse (GRN).', {
        tabs: [['Overview', 'Summary'], ['Requisitions', 'Internal requests for goods'], ['Purchase Orders', 'Orders placed with suppliers'], ['Goods Receipts', 'Record goods that arrive'], ['Reports', 'Reports']],
        steps: ['Create a purchase order and confirm it.', 'When goods arrive, enter the quantities under “Receive Goods / GRN”. This adds the stock.', 'When the supplier’s bill comes, click “Create Purchase Bill” on that receipt, check the rates and post. The stock is not counted again, and the amount payable to the supplier is recorded.'],
        notes: ['Always bill received goods with “Create Purchase Bill”. If you type the same goods as a new purchase, the app warns you.', 'If you do not use goods receipts, simply enter the supplier bill in Purchases; that adds the stock by itself.', 'If the bill quantity differs from the received quantity, the app asks first; stock then follows the bill.', 'Reversing the bill keeps the received goods in stock and lets you bill the receipt again.'],
        faq: [['The app says these items were already received.', 'Press Cancel and use “Create Purchase Bill” on that receipt. Press OK only if these are other goods.']],
    }),
    'inventory-reorder': h('Products that are low on stock and need to be ordered again.', { steps: ['Select products from the list.', 'Create a purchase order or requisition for them.'] }),
    'inventory-orders': h('Purchase orders for inventory.', { steps: ['Click “+ Create Inventory Order” and add the supplier and items.', 'Submit / approve it, then “Mark Ordered”.', 'When goods arrive, click “Receive Goods”. This adds the stock. Bill them with “Create Purchase Bill” on the goods receipt.'] }),

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
    users: h('People who can log in to this business and what they may do.', {
        steps: ['Click “+ Add User” and enter the name, email, role and password.', 'Use Edit to change the name, role or password.', 'Use Deactivate to stop a login, Activate to allow it again.'],
        notes: ['Admin: everything of this business.', 'Staff: New Bill, Sales Invoices, Customers and stock view.', 'Sales: only sales work (billing, invoices, returns, customers, sales orders, stock outward) and current stock.', 'Purchase: only purchase work (purchases, supplier bills, returns, suppliers, stock inward, orders, products) and current stock.', 'Sales and Purchase logins cannot see accounts, reports or settings, and cannot reverse a bill. Receipt and payment entries are made by an admin.', 'A changed role applies from the user’s next screen.', 'The owner login cannot be deactivated.', 'Give each user only the access they need.'],
    }),
    saas: h('Your plan and subscription details.', { steps: ['View your plan and its status.'] }),
    settings: h('Business details that appear on bills and reports, and how bills are printed.', {
        steps: ['Fill in business name, GSTIN, state, phone, email and address.', 'Upload your logo if wanted.', 'Enter bank name, account number, IFSC and account holder to print them on the bill.', 'Write your Terms & Conditions.', 'Choose the default print: A4 or 80mm thermal, and tick the fields to show.', 'Click “Save Settings”.'],
        notes: ['Save your mobile number or GSTIN so that suppliers and customers can find you in Supplier Bills.'],
    }),
};

// Opened from "All guides": the first things a new user asks about.
export const startGuide = h('The order in which to start using BillIQ.', {
    steps: ['Setup > Settings: business name, GSTIN, address, bank details and print format.', 'Setup > Branches, then Inventory > Warehouses / Bins: at least one of each.', 'Setup > Users & Roles: logins for your staff.', 'Inventory > Product Master: add the items with rate and GST %.', 'Inventory > Opening Stock: the stock you have today (remember to Post).', 'Add customers and suppliers.', 'Enter purchases in Purchase > Purchases.', 'Make bills from New Bill.', 'Record money received or paid with Receipt and Payment (Accounting > Vouchers).', 'Check the Business Dashboard daily and Business Reports every month.'],
    faq: [
        ['How do I make a bill?', 'Click “New Bill” at the top, add products, choose payment and click “Complete Sale”.'],
        ['How do I correct a wrong bill?', 'Open Sales Invoices or Purchases, then Actions > Edit > Save Changes.'],
        ['How do I receive pending money?', 'Open the customer in Customers and use “Receive Payment”, or make a Receipt voucher.'],
        ['How do I take back goods?', 'Sales Returns for goods from a customer; Purchase Returns for goods sent to a supplier.'],
        ['Where do I see who owes me money?', 'Business Dashboard > To Collect.'],
    ],
});

export const guideTopics = [
    ['Start here', 'getting-started'], ['Business Dashboard', 'dashboard'], ['Admin Workspace', 'admin-workspace'], ['Setup Checklist', 'onboarding'], ['Staff Workspace', 'staff-workspace'], ['CRM', 'crm'],
    ['New Bill (POS)', 'pos'], ['Sales Invoices', 'sales'], ['Sales Returns', 'sales-returns'], ['Sales Orders and Challans', 'sales|Sales Orders'], ['Customers', 'customers'], ['Payment Reminders', 'payment-reminders'], ['Stock Outward', 'inventory-outward'], ['Reserved Stock', 'inventory-reserved'],
    ['Purchases', 'purchases'], ['Supplier Bills', 'supplier-bills'], ['Purchase Returns', 'purchase-returns'], ['Suppliers', 'suppliers'], ['Reorder Suggestions', 'inventory-reorder'], ['Inventory Orders', 'inventory-orders'], ['Stock Inward / GRN', 'inventory-inward'],
    ['Product Master', 'products'], ['HSN/SAC Master', 'business-hsn-master'], ['Barcode Center', 'inventory-barcode-center'], ['Current Stock', 'inventory-current-stock'], ['Inventory Dashboard', 'inventory'], ['Opening Stock', 'opening-stock'], ['Stock Adjustment', 'inventory-adjustment'], ['Physical Audit', 'inventory-audit'], ['Inventory Vouchers', 'inventory-vouchers'],
    ['Warehouses / Bins', 'inventory-warehouses'], ['Stock Transfer', 'inventory-transfer'], ['Transfer Requests', 'inventory-transfer-requests'], ['Godown Balances', 'inventory-godown-balance'], ['Bins / Racks', 'inventory-bins'], ['Batch & Expiry', 'inventory-batches'], ['Serial Numbers', 'inventory-serials'], ['Batch / Serial Allocation', 'inventory-allocation'], ['Manufacturing / BOM (Kits)', 'inventory-manufacturing'],
    ['Vouchers (Money In / Out)', 'vouchers'], ['Expenses', 'expenses'], ['Ledgers', 'ledgers'], ['GST', 'gst'], ['GST Returns', 'inventory-gst-returns'], ['GST Imports', 'gst-imports'], ['Chart of Accounts', 'accounts'], ['Fixed Assets', 'fixed-assets'], ['Tally Integration', 'tally-integration'],
    ['Business Reports', 'reports'], ['Inventory Reports', 'inventory-reports'], ['Stock Ledger', 'stock-ledger'], ['Stock Valuation', 'inventory-valuation'], ['Voucher Audit Trail', 'inventory-audit-trail'],
    ['Settings and Printing', 'settings'], ['Branches', 'branches'], ['Masters', 'masters'], ['Users and Roles', 'users'], ['Employees', 'employees'], ['Payroll', 'payroll'], ['SaaS Admin', 'saas'], ['Acceptance Matrix', 'setup-acceptance'], ['Company Onboarding', 'company-onboarding'], ['Your Profile', 'profile'],
];

for (const [key, groups] of Object.entries(buttonGuides)) {
    if (pageHelp[key] && groups) pageHelp[key] = { ...pageHelp[key], buttons: groups };
}

export const helpFor = (page, title) => pageHelp[`${page}|${title}`] || pageHelp[page] || null;
