<!DOCTYPE html>
<html>
<head>
<title>Fee Logic Test</title>
require_once __DIR__ . '/../config/security.php';
secure_session_start();
if (($_SESSION['role_name'] ?? '') === 'Barangay Treasurer') {
    http_response_code(403);
    exit('Access denied.');
}
<style>
body{font-family:sans-serif;padding:30px;background:#f0f4f8;}
.box{background:#fff;padding:24px;border-radius:12px;max-width:500px;box-shadow:0 4px 16px rgba(0,0,0,.1);}
select,button{padding:10px 14px;font-size:1rem;border-radius:8px;border:1.5px solid #ddd;width:100%;margin-bottom:12px;}
button{background:#2980b9;color:#fff;border:none;cursor:pointer;}
.fee-box{padding:14px 16px;border-radius:10px;background:#fef9e7;border:1.5px solid #f9ca24;font-size:.9rem;margin-top:4px;}
.fee-row{display:flex;justify-content:space-between;align-items:center;}
#feeAmt{font-size:1.3rem;font-weight:bold;color:#d35400;}
#feeNote{font-size:.85rem;margin-top:6px;font-weight:600;}
</style>
</head>
<body>
<div class="box">
  <h3>Fee Logic Test</h3>
  <p style="font-size:.85rem;color:#666;margin-bottom:16px;">Select a purpose to see fee update:</p>
  
  <select id="purpose" onchange="updateFee()">
    <option value="">-- Select Purpose --</option>
    <option value="Employment">Employment</option>
    <option value="School / Academic Requirements">School / Academic Requirements (Student — FREE)</option>
    <option value="Government Assistance Application">Government Assistance Application</option>
    <option value="Bank / Loan Application">Bank / Loan Application (₱100.00)</option>
    <option value="Travel Requirements">Travel Requirements</option>
  </select>

  <div class="fee-box" id="feeBox">
    <div class="fee-row">
      <span style="color:#555;">Documentary Fee:</span>
      <span id="feeAmt">₱50.00</span>
    </div>
    <div id="feeNote"></div>
  </div>

  <div style="margin-top:16px;padding:12px;background:#f8f9fa;border-radius:8px;font-size:.82rem;">
    <strong>Debug — purpose value sent to server:</strong><br>
    <code id="debugVal" style="color:#c0392b;">none selected</code>
  </div>
</div>

<script>
function updateFee() {
  const sel   = document.getElementById('purpose').value;
  const amt   = document.getElementById('feeAmt');
  const note  = document.getElementById('feeNote');
  const box   = document.getElementById('feeBox');
  const debug = document.getElementById('debugVal');

  debug.textContent = '"' + sel + '"';

  if (sel === 'School / Academic Requirements') {
    amt.textContent  = 'FREE';
    amt.style.color  = '#27ae60';
    box.style.background  = '#eafaf1';
    box.style.borderColor = '#a9dfbf';
    note.innerHTML = '🎓 <span style="color:#27ae60">Student — Fee Waived</span>';
  } else if (sel === 'Bank / Loan Application') {
    amt.textContent  = '₱100.00';
    amt.style.color  = '#d35400';
    box.style.background  = '#fff3e0';
    box.style.borderColor = '#f39c12';
    note.innerHTML = '🏦 <span style="color:#d35400">Loan Application Rate</span>';
  } else if (sel) {
    amt.textContent  = '₱50.00';
    amt.style.color  = '#d35400';
    box.style.background  = '#fef9e7';
    box.style.borderColor = '#f9ca24';
    note.innerHTML = '📄 <span style="color:#888">Regular Fee</span>';
  } else {
    amt.textContent  = '₱50.00';
    amt.style.color  = '#d35400';
    box.style.background  = '#fef9e7';
    box.style.borderColor = '#f9ca24';
    note.innerHTML = '';
  }
}
</script>
</body>
</html>
