# BillIQ user how-to (kaise karein)

## How to add product rates (product ka rate kaise daalein)
Inventory > Product Master > Edit the product > Pricing tab: enter Cost Price, Selling Price and MRP. The filter "Price missing (needs rate)" shows products without a selling price.

## How to make a kit or combo such as a Dental Kit (kit / BOM kaise banayein)
1. Add the kit as a product in Inventory > Product Master.
2. Inventory > Manufacturing / BOM > "New Kit / BOM": choose the kit product, add the items inside with quantity, click "Save Kit".
3. Click "Make Kit", choose the kit and how many, click "Make Kit Now". Item stock goes down, kit stock goes up.
Edit a kit from Actions > Edit. If stock is short, the order is saved; add stock and use Actions > Complete/Post.
Wastage % is extra material lost while making the kit; keep 0 if nothing is lost. Unit comes from Product Master.

## How to send goods without a bill: sample, gift, self use (bina bill maal kaise nikalein)
Sales > Stock Outward > "+ New Outward Entry": choose party, date, warehouse, reason, items and quantity, click "Save & Reduce Stock". For self use create a customer named "Self Use". Do not enter goods that already have a sales bill. This entry changes stock only, not accounts. Ask your CA about GST on gifts and samples.
Products bought as a sample can be tagged: Product Master > Edit > Basic > "Purchased as sample".

## How to enter, edit and pay a purchase bill (purchase bill kaise banayein, edit aur payment)
Purchase > Purchases > "New Purchase", choose supplier, add items, "Confirm & Post". Freight goes in "Transport / Other Charges" with optional GST %. Payment can be Unpaid, Paid in full or Partly paid; later use the "Pay" button. To correct a posted bill use Actions > Edit then "Save Changes"; the voucher number stays the same. A bill with a payment cannot be edited until the payment is reversed. CGST and SGST show for same-state bills, IGST for other states.

## How to record paid, part paid or unpaid sales (sales payment)
In New Bill / POS choose Cash, UPI, Card or Split for fully paid, "Part Paid" to take part of the amount, or "Unpaid" to keep it outstanding. Part Paid and Unpaid need a registered customer, not walk-in.

## How to print barcode labels (barcode kaise print karein)
Product Master: tick products > "Print Labels", set Copies, click Print. The label shows name, barcode with number and price; if no barcode is set the SKU is used. Assign printed packet barcodes in Inventory > Barcode Center > Assign Barcode.
