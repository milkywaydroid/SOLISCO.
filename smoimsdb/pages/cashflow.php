<?php
/* ============================================================
   FILE: pages/staff/cashflow.php
   PURPOSE: Financial tracking (Inflow, Outflow, Net Profit)
   ============================================================ */
require_once '../includes/config.php';
requireStaffLogin();

$message = ''; $error = '';

/**
 * 1. HANDLE FORM SUBMISSION (Recording Outflow)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $cat      = clean($conn, $_POST['category']);
    $desc     = clean($conn, $_POST['description']);
    $amt      = (float)$_POST['amount'];
    $paid_to  = clean($conn, $_POST['paid_to']);
    $receipt  = clean($conn, $_POST['receipt_no']);
    $date     = $_POST['expense_date'];

    if ($amt > 0 && !empty($date)) {
        $stmt = $conn->prepare("INSERT INTO expenses (category, description, amount, paid_to, receipt_no, expense_date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdsss", $cat, $desc, $amt, $paid_to, $receipt, $date);
        
        if ($stmt->execute()) {
            $message = "Expense recorded successfully.";
        } else {
            $error = "Database error: Unable to save expense.";
        }
        $stmt->close();
    } else {
        $error = "Please provide a valid amount and date.";
    }
}

/**
 * 2. CALCULATE FINANCIALS
 */
// Calculate Total Inflow (Sum of Completed Orders)
$inflowRes = $conn->query("SELECT SUM(total_amount) as total FROM orders WHERE status = 'Completed'");
$totalInflow = $inflowRes->fetch_assoc()['total'] ?? 0;

// Calculate Total Outflow (Sum of All Expenses)
$outflowRes = $conn->query("SELECT SUM(amount) as total FROM expenses");
$totalOutflow = $outflowRes->fetch_assoc()['total'] ?? 0;

// Net Profit
$netProfit = $totalInflow - $totalOutflow;

/**
 * 3. FETCH RECENT TRANSACTIONS
 */
$recentExpenses = $conn->query("SELECT * FROM expenses ORDER BY expense_date DESC LIMIT 20");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Flow Management | SolisCo.</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* ===== FIX: Reduce gap between sidebar and main content ===== */
        .layout {
            display: flex;
            min-height: 100vh;
        }
        .main {
            flex: 1;
            padding: 32px 40px;  /* reduced from 32px 40px */
            min-width: 0;
        }

        /* Rest of the original styles (unchanged) */
        :root {
            --pastel-bg: #fbfaff;
            --pastel-border: rgba(167,139,250,.18);
            --pastel-muted: #6b7280;
            --purple-700: #6d28d9;
        }
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        .stat-card { 
            background: #fff; 
            padding: 24px; 
            border-radius: 18px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
            border: 1px solid var(--pastel-border);
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-label { 
            font-size: 0.85rem; 
            color: var(--pastel-muted); 
            text-transform: uppercase; 
            letter-spacing: 1.2px; 
            font-weight: 600;
        }
        .stat-val { 
            font-size: 2rem; 
            font-weight: 800; 
            margin: 10px 0; 
            font-family: 'Inter', sans-serif;
        }
        .type-outflow { color: #dc2626; font-weight: 600; }
        .receipt-code { 
            background: #f3f4f6; 
            padding: 4px 8px; 
            border-radius: 6px; 
            font-family: monospace; 
            font-size: 0.9rem;
            color: #4b5563;
        }
        .modal-overlay { 
            display:none; 
            position:fixed; 
            inset:0; 
            background:rgba(0,0,0,.45); 
            z-index:2000; 
            align-items:center; 
            justify-content:center; 
            backdrop-filter: blur(4px);
        }
        .modal-overlay.open { display:flex; }
        .modal-box { 
            background:#fff; 
            border-radius:20px; 
            width:90%; 
            max-width:550px; 
            padding:32px; 
            position:relative; 
            max-height: 90vh; 
            overflow-y: auto; 
        }
        .form-row { display: flex; gap: 15px; }
        .form-row > div { flex: 1; }
        .btn-primary {
            background: #564586;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 999px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(167,139,250,.4);
        }
        .flex-between {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge {
            display: inline-flex;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .badge-primary {
            background: #ede9fe;
            color: #6d28d9;
        }
        .card {
            background: white;
            border-radius: 18px;
            border: 1px solid var(--pastel-border);
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .table-wrapper {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--pastel-border);
        }
        th {
            background: #f6f4ff;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: var(--pastel-muted);
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: #4b5563;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1.5px solid var(--pastel-border);
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: #a78bfa;
        }
        .w-100 {
            width: 100%;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            background: #f3f4f6;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
        }

    </style>
</head>
<body>

<div class="layout">
    <?php include '../includes/sidebar.php'; ?>

    <div class="main">
        <div class="page-header flex-between">
            <div>
                <h1>💰 Cash Flow</h1>
                <p>Track your business inflow, material costs, and net profit.</p>
            </div>
            <button class="btn btn-primary" onclick="document.getElementById('expenseModal').classList.add('open')">
                + Record New Expense
            </button>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Inflow (Sales)</div>
                <div class="stat-val" style="color:#16a34a">₱<?= number_format($totalInflow, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Outflow (Costs)</div>
                <div class="stat-val" style="color:#dc2626">₱<?= number_format($totalOutflow, 2) ?></div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #fdfcfb 0%, #e2d1f9 100%);">
                <div class="stat-label">Net Profit</div>
                <div class="stat-val" style="color:#6d28d9">₱<?= number_format($netProfit, 2) ?></div>
            </div>
        </div>

        <div class="card">
            <div class="flex-between" style="margin-bottom: 20px;">
                <h2 style="font-size: 1.2rem;">Transaction History</h2>
                <div class="badge badge-primary">Recent 20 Records</div>
            </div>
            
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Receipt #</th>
                            <th>Paid To</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($recentExpenses->num_rows > 0): ?>
                            <?php while($row = $recentExpenses->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($row['expense_date'])) ?></td>
                                <td><span class="receipt-code"><?= htmlspecialchars($row['receipt_no'] ?: 'NONE') ?></span></td>
                                <td><strong><?= htmlspecialchars($row['paid_to']) ?></strong></td>
                                <td><?= htmlspecialchars($row['description']) ?></td>
                                <td><span class="badge" style="background:#f3e8ff; color:#7e22ce;"><?= htmlspecialchars($row['category']) ?></span></td>
                                <td class="type-outflow">- ₱<?= number_format($row['amount'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 40px; color: var(--pastel-muted);">No expenses recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="expenseModal">
    <div class="modal-box">
        <button class="modal-close" onclick="document.getElementById('expenseModal').classList.remove('open')">✕</button>
        <h2 style="margin-bottom: 20px;">Record Business Expense</h2>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Paid To (Vendor or Person)</label>
                <input type="text" name="paid_to" class="form-control" placeholder="e.g. Supplier Name, Store, or Staff" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Receipt / Reference #</label>
                    <input type="text" name="receipt_no" class="form-control" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label class="form-label">Amount (₱)</label>
                    <input type="number" name="amount" step="0.01" class="form-control" placeholder="0.00" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-control" required>
                    <option value="Raw Materials">Raw Materials</option>
                    <option value="Packaging">Packaging</option>
                    <option value="Labor/Service">Labor / Service</option>
                    <option value="Utilities">Utilities</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Description / Particulars</label>
                <textarea name="description" class="form-control" rows="2" placeholder="What was this payment for?"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Transaction Date</label>
                <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div style="margin-top: 25px;">
                <button type="submit" name="add_expense" class="btn btn-primary w-100" style="padding: 14px;">
                    Save Transaction
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Close modal when clicking outside of the box
    window.onclick = function(event) {
        const modal = document.getElementById('expenseModal');
        if (event.target == modal) {
            modal.classList.remove('open');
        }
    }
</script>

</body>
</html>