<?php
$page_title = 'Financial Reports';
$active_page = 'finance';
$breadcrumb = ['Finance', 'Financial Reports'];
require_once '../includes/auth_check.php';
if (!in_array($_SESSION['role_name'] ?? '', ['System Administrator', 'Barangay Treasurer'], true)) {
    http_response_code(403);
    exit('Access denied.');
}
require_permission('view_financial_reports');

$conn = getDBConnection();
$csrf = generate_csrf_token();
$can_manage = can('manage_financial_reports');
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');
$date_from = trim((string)($_GET['from'] ?? $month_start));
$date_to = trim((string)($_GET['to'] ?? $month_end));
$date_valid = static function ($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $parts = explode('-', $date);
    return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
};
if (!$date_valid($date_from)) $date_from = $month_start;
if (!$date_valid($date_to)) $date_to = $month_end;
if ($date_from > $date_to) { $swap = $date_from; $date_from = $date_to; $date_to = $swap; }

$summary_stmt = $conn->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN entry_type='INCOME' THEN amount ELSE 0 END),0) AS total_income,
       COALESCE(SUM(CASE WHEN entry_type='EXPENSE' THEN amount ELSE 0 END),0) AS total_expenses
     FROM tbl_financial_entries WHERE entry_date BETWEEN ? AND ?"
);
$summary_stmt->bind_param('ss', $date_from, $date_to);
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();
$summary_stmt->close();
$total_income = (float)$summary['total_income'];
$total_expenses = (float)$summary['total_expenses'];
$net_balance = $total_income - $total_expenses;

$entries_stmt = $conn->prepare(
    "SELECT fe.financial_entry_id, fe.entry_type, fe.category, fe.amount, fe.entry_date,
            fe.description, fe.source_request_id, dr.request_code
     FROM tbl_financial_entries fe
     LEFT JOIN tbl_document_requests dr ON dr.request_id=fe.source_request_id
     WHERE fe.entry_date BETWEEN ? AND ?
     ORDER BY fe.entry_date DESC, fe.financial_entry_id DESC"
);
$entries_stmt->bind_param('ss', $date_from, $date_to);
$entries_stmt->execute();
$entries = $entries_stmt->get_result();
$entry_rows = [];
while ($row = $entries->fetch_assoc()) $entry_rows[] = $row;
$entries_stmt->close();
write_audit_log((int)$_SESSION['user_id'], 'VIEW_FINANCIAL_REPORT', 'financial_ledger', "Range:$date_from..$date_to Entries:".count($entry_rows));
$csrf_json = json_encode($csrf);
require_once '../includes/header.php';
?>
<div class="page-header d-flex justify-between align-center flex-wrap gap-2">
  <div>
    <h2><i class="fas fa-coins"></i> Financial Reports</h2>
    <p>Barangay San Isidro — Receipts and Expenditures</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($can_manage): ?>
    <button type="button" class="btn btn-secondary" onclick="importDocumentFees()" title="Add assessed fees for issued documents not yet in the financial ledger"><i class="fas fa-file-import"></i> Import Assessed Document Fees</button>
    <button type="button" class="btn btn-primary" onclick="openFinanceModal()"><i class="fas fa-plus"></i> Add Entry</button>
    <?php endif; ?>
    <button type="button" class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
  </div>
</div>

<form method="GET" class="card mb-3 no-print">
  <div class="card-body d-flex gap-2 align-center flex-wrap" style="padding:14px 18px;">
    <label class="d-flex align-center gap-2">From <input type="date" name="from" class="form-control" required value="<?= sanitize_output($date_from) ?>"></label>
    <label class="d-flex align-center gap-2">To <input type="date" name="to" class="form-control" required value="<?= sanitize_output($date_to) ?>"></label>
    <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Apply Range</button>
    <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>" class="btn btn-secondary">This Month</a>
    <a href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-03-31') ?>" class="btn btn-secondary">Q1</a>
    <a href="?from=<?= date('Y-04-01') ?>&to=<?= date('Y-06-30') ?>" class="btn btn-secondary">Q2</a>
    <a href="?from=<?= date('Y-07-01') ?>&to=<?= date('Y-09-30') ?>" class="btn btn-secondary">Q3</a>
    <a href="?from=<?= date('Y-10-01') ?>&to=<?= date('Y-12-31') ?>" class="btn btn-secondary">Q4</a>
  </div>
</form>

<div id="financialReport">
  <header class="financial-print-header">
    <div class="print-rule"></div>
    <h1>Barangay San Isidro</h1>
    <h2>Statement of Receipts and Expenditures</h2>
    <p>Reporting Period: <?= date('F d, Y', strtotime($date_from)) ?> to <?= date('F d, Y', strtotime($date_to)) ?></p>
    <p>Date Generated: <?= date('F d, Y h:i A') ?></p>
    <div class="print-rule"></div>
  </header>

  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-bottom:20px;">
    <div class="stat-card"><div class="stat-icon green"><i class="fas fa-arrow-trend-up"></i></div><div><div class="stat-value">₱<?= number_format($total_income, 2) ?></div><div class="stat-label">Total Income / Receipts</div></div></div>
    <div class="stat-card"><div class="stat-icon red"><i class="fas fa-arrow-trend-down"></i></div><div><div class="stat-value">₱<?= number_format($total_expenses, 2) ?></div><div class="stat-label">Total Expenses</div></div></div>
    <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-scale-balanced"></i></div><div><div class="stat-value">₱<?= number_format($net_balance, 2) ?></div><div class="stat-label">Net Balance</div></div></div>
  </div>

  <div class="card">
    <div class="card-header"><h3><i class="fas fa-list"></i> Ledger Entries</h3><span class="badge badge-secondary"><?= count($entry_rows) ?> entries</span></div>
    <div class="card-body" style="padding:0;">
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th>Source</th><th style="text-align:right;">Amount</th><?php if ($can_manage): ?><th class="no-print">Actions</th><?php endif; ?></tr></thead>
          <tbody>
          <?php if ($entry_rows): foreach ($entry_rows as $entry): ?>
            <tr>
              <td><?= date('M d, Y', strtotime($entry['entry_date'])) ?></td>
              <td><span class="badge badge-<?= $entry['entry_type'] === 'INCOME' ? 'success' : 'danger' ?>"><?= $entry['entry_type'] === 'INCOME' ? 'Receipt' : 'Expense' ?></span></td>
              <td><?= sanitize_output($entry['category']) ?></td>
              <td><?= nl2br(sanitize_output($entry['description'])) ?></td>
              <td><?php if ($entry['request_code']): ?><span class="badge badge-warning">Assessed · <?= sanitize_output($entry['request_code']) ?></span><?php else: ?>Manual<?php endif; ?></td>
              <td style="text-align:right;white-space:nowrap;">₱<?= number_format((float)$entry['amount'], 2) ?></td>
              <?php if ($can_manage): ?>
              <td class="no-print"><div class="d-flex gap-2"><button type="button" class="btn btn-icon btn-sm" title="Edit entry" onclick='editFinanceEntry(<?= json_encode(['financial_entry_id'=>(int)$entry['financial_entry_id'],'entry_type'=>$entry['entry_type'],'category'=>$entry['category'],'amount'=>$entry['amount'],'entry_date'=>$entry['entry_date'],'description'=>$entry['description']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button><button type="button" class="btn btn-icon btn-sm" title="Delete entry" onclick="deleteFinanceEntry(<?= (int)$entry['financial_entry_id'] ?>)"><i class="fas fa-trash"></i></button></div></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="<?= $can_manage ? 7 : 6 ?>"><div class="empty-state"><i class="fas fa-file-invoice-dollar"></i><p>No financial entries in this date range.</p></div></td></tr>
          <?php endif; ?>
          </tbody>
          <tfoot><tr><th colspan="5">Net balance for period</th><th style="text-align:right;">₱<?= number_format($net_balance, 2) ?></th><?php if ($can_manage): ?><th class="no-print"></th><?php endif; ?></tr></tfoot>
        </table>
      </div>
    </div>
  </div>
  <p class="assessment-note">Assessed document fees are calculated from issued documents (stored, released, or legacy printed records); they are not proof of cash collection. Verify and reconcile them against official receipts.</p>
</div>

<?php if ($can_manage): ?>
<div class="modal-overlay" id="financeEntryModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header"><h4 id="financeModalTitle">Add Financial Entry</h4><button type="button" class="modal-close" onclick="closeFinanceModal()"><i class="fas fa-xmark"></i></button></div>
    <div class="modal-body">
      <input type="hidden" id="financeEntryId">
      <div class="form-grid">
        <div class="form-group"><label for="financeType">Entry Type</label><select id="financeType" class="form-control"><option value="INCOME">Income / Receipt</option><option value="EXPENSE">Expense / Disbursement</option></select></div>
        <div class="form-group"><label for="financeCategory">Category</label><input id="financeCategory" class="form-control" maxlength="100" required placeholder="e.g. Business Permit Fees"></div>
        <div class="form-group"><label for="financeAmount">Amount (PHP)</label><input id="financeAmount" class="form-control" type="number" min="0.01" max="9999999999.99" step="0.01" required></div>
        <div class="form-group"><label for="financeDate">Date</label><input id="financeDate" class="form-control" type="date" required value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group" style="grid-column:1/-1;"><label for="financeDescription">Description</label><textarea id="financeDescription" class="form-control" rows="3" maxlength="2000" required placeholder="Describe the receipt or disbursement"></textarea></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeFinanceModal()">Cancel</button><button type="button" class="btn btn-primary" onclick="saveFinanceEntry()"><i class="fas fa-save"></i> Save Entry</button></div>
  </div>
</div>
<script>
const FINANCE_CSRF = <?= $csrf_json ?>;
function closeFinanceModal() { const modal=document.getElementById('financeEntryModal'); modal.classList.remove('show'); modal.style.visibility='hidden'; modal.style.opacity='0'; document.body.style.overflow=''; }
function showFinanceModal() { const modal=document.getElementById('financeEntryModal'); modal.classList.add('show'); modal.style.visibility='visible'; modal.style.opacity='1'; document.body.style.overflow='hidden'; }
function openFinanceModal() {
  document.getElementById('financeEntryId').value=''; document.getElementById('financeType').value='INCOME';
  document.getElementById('financeCategory').value=''; document.getElementById('financeAmount').value='';
  document.getElementById('financeDate').value='<?= date('Y-m-d') ?>'; document.getElementById('financeDescription').value='';
  document.getElementById('financeModalTitle').textContent='Add Financial Entry'; showFinanceModal();
}
function editFinanceEntry(entry) {
  document.getElementById('financeEntryId').value=entry.financial_entry_id; document.getElementById('financeType').value=entry.entry_type;
  document.getElementById('financeCategory').value=entry.category; document.getElementById('financeAmount').value=entry.amount;
  document.getElementById('financeDate').value=entry.entry_date; document.getElementById('financeDescription').value=entry.description;
  document.getElementById('financeModalTitle').textContent='Edit Financial Entry'; showFinanceModal();
}
document.getElementById('financeEntryModal')?.addEventListener('click', event => { if(event.target.id==='financeEntryModal') closeFinanceModal(); });
async function saveFinanceEntry() {
  const id=document.getElementById('financeEntryId').value;
  const data={csrf_token:FINANCE_CSRF,entry_type:document.getElementById('financeType').value,category:document.getElementById('financeCategory').value.trim(),amount:document.getElementById('financeAmount').value,entry_date:document.getElementById('financeDate').value,description:document.getElementById('financeDescription').value.trim()};
  if(!data.category || !data.amount || !data.entry_date || !data.description) { showToast('error','Complete all financial entry fields.'); return; }
  if(id) data.financial_entry_id=id;
  const result=await apiRequest('/BRGYMS/finance/api.php?action='+(id?'update':'create'),data);
  if(!result.success) { showToast('error',result.message||'Could not save entry.'); return; }
  closeFinanceModal(); showToast('success',result.message); window.location.reload();
}
async function deleteFinanceEntry(id) {
  if(!confirm('Delete this financial ledger entry? This action is audit logged.')) return;
  const result=await apiRequest('/BRGYMS/finance/api.php?action=delete',{csrf_token:FINANCE_CSRF,financial_entry_id:id});
  if(!result.success) { showToast('error',result.message||'Could not delete entry.'); return; }
  showToast('success',result.message); window.location.reload();
}
async function importDocumentFees() {
  if(!confirm('Import assessed fees for issued documents not yet in the ledger? These amounts are not proof of cash collection.')) return;
  const result=await apiRequest('/BRGYMS/finance/api.php?action=import_document_fees',{csrf_token:FINANCE_CSRF});
  if(!result.success) { showToast('error',result.message||'Could not import fees.'); return; }
  showToast('success',result.message); window.location.reload();
}
</script>
<?php endif; ?>
<style>
.financial-print-header{display:none;text-align:center;font-family:"Times New Roman",serif;margin-bottom:18px}.financial-print-header h1{font-size:17pt;margin:0 0 5px}.financial-print-header h2{font-size:14pt;margin:0 0 8px}.financial-print-header p{font-size:10pt;margin:3px 0}.print-rule{border-top:3px double #000;margin:8px 0}
.assessment-note{font-size:.8rem;color:var(--text-muted);margin-top:12px}
@media print{@page{size:A4 portrait;margin:16mm 14mm 18mm;@bottom-center{content:"Page " counter(page) " of " counter(pages);font:9pt "Times New Roman",serif;color:#555}}.sidebar,.topbar,.no-print,.page-header,.assessment-note{display:none!important}.main-content{margin:0!important;padding:0!important}.page-content{padding:0!important}.financial-print-header{display:block;break-after:avoid}.card{box-shadow:none!important;border:0!important}.card-header{border-bottom:1px solid #777!important}.table-wrapper{overflow:visible!important}body{background:#fff!important;color:#000!important}.stats-grid{break-inside:avoid}table{font-size:9pt!important;border-collapse:collapse}thead{display:table-header-group}tfoot{display:table-footer-group}tr{break-inside:avoid}th,td{padding:5px!important}}
</style>
<?php require_once '../includes/footer.php'; ?>
