<?php
require_once __DIR__ . '/../includes/resident_portal_auth.php';
$account = resident_portal_current_account();
$conn = getDBConnection();
$resident_id = (int)$account['resident_id'];
$first_name = aes_decrypt($account['first_name']);
$middle_name = aes_decrypt($account['middle_name']);
$last_name = aes_decrypt($account['last_name']);
$birth_date = aes_decrypt($account['birth_date']);
$contact = aes_decrypt($account['contact_number']);
$full_name = trim($first_name.' '.($middle_name ? $middle_name.' ' : '').$last_name);
$csrf = generate_csrf_token();
$csrf_json = json_encode($csrf);

$types = $conn->query("SELECT type_id,type_name,fee FROM tbl_document_types WHERE is_active=1 ORDER BY type_name");
$request_stmt = $conn->prepare(
    "SELECT dr.request_code,dr.purpose,dr.status,dr.requested_at,dr.preferred_pickup_date,dr.drawer_location,dt.type_name
     FROM tbl_document_requests dr
     JOIN tbl_document_types dt ON dt.type_id=dr.document_type_id
     WHERE dr.resident_id=?
     ORDER BY dr.requested_at DESC LIMIT 100"
);
$request_stmt->bind_param('i', $resident_id);
$request_stmt->execute();
$requests = $request_stmt->get_result();
$request_rows = [];
while ($row = $requests->fetch_assoc()) $request_rows[] = $row;
$request_stmt->close();
$status_class = ['PENDING'=>'warning','APPROVED'=>'info','STORED'=>'warning','RELEASED'=>'success','PRINTED'=>'success','REJECTED'=>'danger'];
$status_label = ['STORED'=>'Awaiting Pickup','RELEASED'=>'Released'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resident Portal – Barangay San Isidro</title>
<link rel="stylesheet" href="/assets/css/main.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
body{background:#edf3f1}.portal-layout{min-height:100vh}.portal-top{background:#174d40;color:#fff;padding:14px max(20px,calc((100vw - 1160px)/2));display:flex;align-items:center;justify-content:space-between;gap:14px}.portal-brand{display:flex;align-items:center;gap:12px}.portal-brand img{width:46px;height:46px;object-fit:contain}.portal-brand strong{display:block;font-size:1rem}.portal-brand small{opacity:.8}.portal-top a{color:#fff;text-decoration:none}.portal-main{max-width:1160px;margin:24px auto;padding:0 20px}.portal-welcome{margin-bottom:18px}.portal-welcome h1{font-size:1.35rem;margin:0 0 4px;color:#194e42}.portal-welcome p{margin:0;color:#5b7069;font-size:.9rem}.portal-grid{display:grid;grid-template-columns:320px 1fr;gap:16px;align-items:start}.portal-card{background:#fff;border:1px solid #d9e3df;border-radius:8px;padding:18px}.portal-card h2{font-size:1rem;margin:0 0 14px}.portal-profile-row{padding:8px 0;border-bottom:1px solid #edf1ef;font-size:.86rem}.portal-profile-row:last-child{border-bottom:0}.portal-alert{display:none;margin:12px 0;padding:10px 12px;border-radius:6px;font-size:.85rem}.portal-alert.error{background:#fdecea;color:#942d24}.portal-alert.success{background:#e8f5ee;color:#245c42}.portal-card .form-group{margin-bottom:12px}.request-table td{vertical-align:top}.portal-note{font-size:.78rem;color:#64766f;line-height:1.5;margin-top:10px}@media(max-width:760px){.portal-grid{grid-template-columns:1fr}.portal-top{padding:12px 16px}}
</style>
<style>
.portal-account{display:flex;align-items:center;gap:10px;min-width:0}
.portal-avatar{display:grid;width:34px;height:34px;flex:0 0 34px;place-items:center;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:.76rem;font-weight:700}
.portal-account-name{max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem}
.portal-signout{display:inline-flex;min-height:36px;align-items:center;justify-content:center;gap:7px;padding:0 11px;border:1px solid rgba(255,255,255,.45);border-radius:6px;color:#fff;text-decoration:none;font-size:.78rem;font-weight:600}
.portal-signout:hover{background:rgba(255,255,255,.1)}
.request-cards{display:none}
@media(max-width:760px){
  body{background:#f2f5f3}
  .portal-top{min-height:62px;padding:9px 14px;background:#0f5132;gap:8px}
  .portal-brand{gap:9px;min-width:0}
  .portal-brand img{width:38px;height:38px;flex:0 0 38px}
  .portal-brand strong{font-size:.82rem;white-space:nowrap}
  .portal-brand small{font-size:.66rem;white-space:nowrap}
  .portal-account{gap:7px}
  .portal-account-name{display:none}
  .portal-avatar{width:31px;height:31px;flex-basis:31px}
  .portal-signout{width:36px;min-height:36px;padding:0;border-radius:50%}
  .portal-signout span{display:none}
  .portal-main{width:100%;margin:0 auto;padding:18px 14px 30px}
  .portal-welcome{margin-bottom:15px}
  .portal-welcome h1{font-size:1.22rem;line-height:1.3;color:#174a34}
  .portal-welcome p{font-size:.82rem;line-height:1.5}
  .portal-grid{grid-template-columns:minmax(0,1fr);gap:13px}
  .portal-grid>div{display:flex;flex-direction:column;gap:13px;min-width:0}
  .portal-card{min-width:0;padding:16px;border:1px solid #e5e9e7;border-radius:12px;background:#fff;box-shadow:0 3px 12px rgba(23,45,34,.05)}
  .portal-card h2{display:flex;align-items:center;gap:8px;margin-bottom:13px;font-size:.96rem;line-height:1.35;color:#25374a}
  .portal-profile-row{padding:9px 0;font-size:.83rem;line-height:1.45;overflow-wrap:anywhere}
  .portal-profile-row code{font-size:.8rem;overflow-wrap:anywhere}
  .portal-note{font-size:.75rem;line-height:1.55}
  .portal-card .form-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:0}
  .portal-card .form-grid>.form-group,.portal-card .form-grid>[style*="grid-column"]{grid-column:1/-1!important;min-width:0}
  .portal-card .form-group{margin-bottom:13px}
  .portal-card .form-group label{display:block;margin-bottom:6px;font-size:.8rem;font-weight:600}
  .portal-card .form-control{display:block;width:100%;min-width:0;min-height:48px;padding:11px 12px;border:1px solid #dce3e8;border-radius:8px;background:#fff;font-size:16px}
  .portal-card select.form-control{height:48px}
  .portal-card textarea.form-control{min-height:108px;resize:vertical}
  .portal-card input[type="date"].form-control{appearance:auto}
  .portal-card .form-group small{display:block;margin-top:5px;line-height:1.4}
  .portal-submit,.portal-card form>.btn{display:flex;width:100%;min-height:48px;justify-content:center;border-radius:8px;background:#0d6efd;color:#fff;font-size:.88rem;font-weight:700}
  .portal-card form>.btn:hover{background:#0b5ed7}
  .portal-card[style*="margin-bottom"]{margin-bottom:0!important}
  .portal-card .table-wrapper{display:none}
  .request-cards{display:grid;gap:9px}
  .request-card{min-width:0;padding:13px;border:1px solid #e4e9ed;border-radius:9px;background:#fff}
  .request-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
  .request-card-top code{min-width:0;color:#34495e;font-size:.73rem;overflow-wrap:anywhere}
  .request-card .badge{flex:0 0 auto;padding:4px 9px;border-radius:999px;font-size:.65rem;white-space:normal;text-align:center}
  .request-card .badge-warning{background:#fff3cd;color:#8a6500}
  .request-card .badge-success{background:#dff2e5;color:#237447}
  .request-card .badge-info{background:#e2efff;color:#245b9b}
  .request-card .badge-danger{background:#fde5e5;color:#a13737}
  .request-card .badge-secondary{background:#edf0f2;color:#5d6871}
  .request-card h3{margin:9px 0 3px;color:#25374a;font-size:.87rem;line-height:1.4}
  .request-card-purpose{margin:0;color:#6b7781;font-size:.75rem;line-height:1.45;overflow-wrap:anywhere}
  .request-card-dates{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0 0;padding-top:10px;border-top:1px solid #edf0f2}
  .request-card-dates dt{color:#79858e;font-size:.66rem}
  .request-card-dates dd{margin:3px 0 0;color:#334454;font-size:.75rem;font-weight:600}
}
@media(max-width:360px){.portal-main{padding-right:10px;padding-left:10px}.portal-card{padding:13px}.portal-brand strong{font-size:.76rem}.portal-brand small{font-size:.62rem}.request-card-dates{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="portal-layout">
  <header class="portal-top">
    <div class="portal-brand"><img src="/assets/img/brgy_seal.png" alt="Barangay seal"><div><strong>Barangay San Isidro</strong><small>Resident Services Portal</small></div></div>
    <div class="portal-account"><span class="portal-avatar" aria-hidden="true"><?= sanitize_output(mb_strtoupper(mb_substr($first_name, 0, 1, 'UTF-8').mb_substr($last_name, 0, 1, 'UTF-8'), 'UTF-8')) ?></span><span class="portal-account-name"><?= sanitize_output($account['username']) ?></span><a href="logout.php" class="portal-signout" aria-label="Sign out" title="Sign out"><i class="fas fa-right-from-bracket" aria-hidden="true"></i><span>Sign Out</span></a></div>
  </header>
  <main class="portal-main">
    <div class="portal-welcome"><h1>Hello, <?= sanitize_output($first_name) ?></h1><p>View your resident information and track document requests.</p></div>
    <div class="portal-grid">
      <section class="portal-card">
        <h2><i class="fas fa-id-card"></i> My Resident Profile</h2>
        <div class="portal-profile-row"><strong>Resident Code</strong><br><code><?= sanitize_output($account['resident_code']) ?></code></div>
        <div class="portal-profile-row"><strong>Full Name</strong><br><?= sanitize_output($full_name) ?></div>
        <div class="portal-profile-row"><strong>Date of Birth</strong><br><?= sanitize_output($birth_date ? date('M d, Y', strtotime($birth_date)) : '—') ?></div>
        <div class="portal-profile-row"><strong>Address</strong><br><?= sanitize_output($account['address'] ?: '—') ?></div>
        <div class="portal-profile-row"><strong>Contact Number</strong><br><?= sanitize_output($contact ?: '—') ?></div>
        <p class="portal-note">This profile is read-only. Contact the barangay office to correct resident information.</p>
      </section>

      <div>
        <section class="portal-card" style="margin-bottom:16px;">
          <h2><i class="fas fa-file-circle-plus"></i> Request a Document</h2>
          <div class="portal-alert error" id="requestError" role="alert"></div>
          <div class="portal-alert success" id="requestSuccess" role="status"></div>
          <form id="portalRequestForm">
            <input type="hidden" name="csrf_token" value="<?= sanitize_output($csrf) ?>">
            <div class="form-grid">
              <div class="form-group"><label for="portalDocumentType">Document Type</label><select id="portalDocumentType" name="document_type_id" class="form-control" required><option value="">Select a document</option><?php if ($types): while ($type = $types->fetch_assoc()): ?><option value="<?= (int)$type['type_id'] ?>"><?= sanitize_output($type['type_name']) ?><?= (float)$type['fee'] > 0 ? ' — ₱'.number_format((float)$type['fee'],2) : ' — FREE' ?></option><?php endwhile; endif; ?></select></div>
              <div class="form-group"><label for="pickupDate">Preferred Pickup Date <small>(optional)</small></label><input id="pickupDate" name="preferred_pickup_date" type="date" class="form-control" min="<?= date('Y-m-d', strtotime('+1 day')) ?>"><small>Leave blank for normal processing.</small></div>
              <div class="form-group" style="grid-column:1/-1;"><label for="requestPurpose">Purpose</label><textarea id="requestPurpose" name="purpose" class="form-control" rows="2" maxlength="500" required placeholder="What do you need this document for?"></textarea></div>
            </div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> Submit Request</button>
          </form>
          <p class="portal-note">Requests are reviewed by barangay staff. A preferred pickup date is a request, not a guaranteed release date.</p>
        </section>

        <section class="portal-card">
          <h2><i class="fas fa-list-check"></i> My Document Requests</h2>
          <div class="request-cards" aria-label="Document request history">
            <?php if ($request_rows): foreach ($request_rows as $request): ?>
            <article class="request-card">
              <div class="request-card-top"><code><?= sanitize_output($request['request_code']) ?></code><span class="badge badge-<?= $status_class[$request['status']] ?? 'secondary' ?>"><?= sanitize_output($status_label[$request['status']] ?? $request['status']) ?></span></div>
              <h3><?= sanitize_output($request['type_name']) ?></h3>
              <p class="request-card-purpose"><?= sanitize_output($request['purpose']) ?></p>
              <dl class="request-card-dates"><div><dt>Pickup</dt><dd><?php if ($request['status'] === 'STORED' && !empty($request['drawer_location'])): ?><?= sanitize_output($request['drawer_location']) ?><?php elseif ($request['preferred_pickup_date']): ?><?= date('M d, Y', strtotime($request['preferred_pickup_date'])) ?><?php else: ?>Standard<?php endif; ?></dd></div><div><dt>Submitted</dt><dd><?= date('M d, Y', strtotime($request['requested_at'])) ?></dd></div></dl>
            </article>
            <?php endforeach; else: ?>
            <div class="empty-state" style="padding:22px;"><i class="fas fa-folder-open"></i><p>You have no document requests yet.</p></div>
            <?php endif; ?>
          </div>
          <div class="table-wrapper">
            <table class="request-table">
              <thead><tr><th>Request</th><th>Document</th><th>Pickup</th><th>Status</th><th>Submitted</th></tr></thead>
              <tbody>
              <?php if ($request_rows): foreach ($request_rows as $request): ?>
                <tr>
                  <td><code><?= sanitize_output($request['request_code']) ?></code><br><small><?= sanitize_output($request['purpose']) ?></small></td>
                  <td><?= sanitize_output($request['type_name']) ?></td>
                  <td><?php if ($request['status'] === 'STORED' && !empty($request['drawer_location'])): ?><?= sanitize_output($request['drawer_location']) ?><?php elseif ($request['preferred_pickup_date']): ?><?= date('M d, Y', strtotime($request['preferred_pickup_date'])) ?><?php else: ?>Standard<?php endif; ?></td>
                  <td><span class="badge badge-<?= $status_class[$request['status']] ?? 'secondary' ?>"><?= sanitize_output($status_label[$request['status']] ?? $request['status']) ?></span></td>
                  <td><?= date('M d, Y', strtotime($request['requested_at'])) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="5"><div class="empty-state" style="padding:22px;"><i class="fas fa-folder-open"></i><p>You have no document requests yet.</p></div></td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </main>
</div>
<script>
document.getElementById('portalRequestForm').addEventListener('submit', async event => {
  event.preventDefault();
  const form=event.currentTarget, error=document.getElementById('requestError'), success=document.getElementById('requestSuccess');
  error.style.display='none'; success.style.display='none';
  try {
    const response=await fetch('api.php?action=create_request',{method:'POST',body:new FormData(form)});
    const result=await response.json();
    if(!result.success){error.textContent=result.message||'Could not submit request.';error.style.display='block';return;}
    success.textContent=`Request ${result.request_code} submitted for staff review.`;success.style.display='block';
    setTimeout(()=>window.location.reload(),1000);
  } catch (exception) {
    error.textContent='Unable to contact the portal. Please try again.';error.style.display='block';
  }
});
</script>
</body>
</html>
