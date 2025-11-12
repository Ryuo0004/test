<?php
$baseDir = __DIR__;
if (file_exists($baseDir . '/admin_init.php')) {
  include 'admin_init.php';
} else {
  include 'db_connect.php';
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  $today = date('Y-m-d');
}
if (file_exists($baseDir . '/admin_actions.php')) {
  include 'admin_actions.php';
}
// Load clinic branding for welcome banner
if (file_exists($baseDir . '/settings_helper.php')) {
  include_once 'settings_helper.php';
}


$patientCount = 0;
$apptCount = 0;
$weeklyAppts = ['Mon'=>0,'Tue'=>0,'Wed'=>0,'Thu'=>0,'Fri'=>0,'Sat'=>0,'Sun'=>0];
// Additional stats
$procedureStats = [];
$pendingApprovals = [];
$invCounts = ['low' => 0, 'out' => 0];
$lowStockItems = [];
$inventoryTotalItems = 0;
$dentistAlert = '';


if (isset($conn)) {
  $r = $conn->query("SELECT COUNT(*) AS total FROM tbl_patient");
  if ($r) { $patientCount = (int)$r->fetch_assoc()['total']; }

  $stmt = $conn->prepare("SELECT COUNT(*) AS today FROM tbl_appointments WHERE Date = ? AND LOWER(COALESCE(Status,'')) NOT IN ('confirmed','confirm')");
  if ($stmt) {
    $stmt->bind_param('s', $today);
    if ($stmt->execute()) { $apptCount = (int)$stmt->get_result()->fetch_assoc()['today']; }
    $stmt->close();
  }

  $r = $conn->query("SELECT DATE_FORMAT(Date, '%a') d, COUNT(*) c
                      FROM tbl_appointments
                      WHERE YEARWEEK(Date, 1) = YEARWEEK(CURDATE(), 1)
                        AND LOWER(COALESCE(Status,'')) NOT IN ('confirmed','confirm')
                      GROUP BY d");
  if ($r) { while ($row = $r->fetch_assoc()) { if (isset($weeklyAppts[$row['d']])) { $weeklyAppts[$row['d']] = (int)$row['c']; } } }

  // Top treatments (current month) from treatment history; fallback to finished appointments
  $monthStart = date('Y-m-01');
  $monthEnd = date('Y-m-t');
  $ps = $conn->prepare("SELECT Procedure_Name, COUNT(*) cnt FROM tbl_treatment_history WHERE Treatment_Date BETWEEN ? AND ? GROUP BY Procedure_Name ORDER BY cnt DESC");
  if ($ps) {
    $ps->bind_param('ss', $monthStart, $monthEnd);
    if ($ps->execute()) {
      $res = $ps->get_result();
      while ($row = $res->fetch_assoc()) { $procedureStats[$row['Procedure_Name']] = (int)$row['cnt']; }
    }
    $ps->close();
  }
  if (empty($procedureStats)) {
    $ps2 = $conn->prepare("SELECT `Procedure` AS Procedure_Name, COUNT(*) cnt FROM tbl_appointments WHERE Date BETWEEN ? AND ? AND Status = 'Finished' GROUP BY `Procedure` ORDER BY cnt DESC");
    if ($ps2) {
      $ps2->bind_param('ss', $monthStart, $monthEnd);
      if ($ps2->execute()) {
        $res2 = $ps2->get_result();
        while ($row = $res2->fetch_assoc()) { $procedureStats[$row['Procedure_Name']] = (int)$row['cnt']; }
      }
      $ps2->close();
    }
  }
  // Compute OK count (not low and not out)
  $okCount = max(0, (int)$inventoryTotalItems - (int)$invCounts['low'] - (int)$invCounts['out']);

  // Inventory alert counts (low and out of stock)
  $hasInvTbl = $conn->query("SHOW TABLES LIKE 'tbl_inventory'");
  if ($hasInvTbl && $hasInvTbl->num_rows > 0) {
    // Total inventory items for OK count
    $tc = $conn->query("SELECT COUNT(*) AS total FROM tbl_inventory");
    if ($tc) { $inventoryTotalItems = (int)($tc->fetch_assoc()['total'] ?? 0); }
    $ir = $conn->query("SELECT 
        SUM(CASE WHEN Quantity = 0 THEN 1 ELSE 0 END) AS out_count,
        SUM(CASE WHEN Quantity > 0 AND Quantity < 5 THEN 1 ELSE 0 END) AS low_count
      FROM tbl_inventory");
    if ($ir) {
      $row = $ir->fetch_assoc();
      $invCounts['out'] = (int)($row['out_count'] ?? 0);
      $invCounts['low'] = (int)($row['low_count'] ?? 0);
    }

    // Fetch up to 5 low-stock items (exclude out-of-stock) for dashboard card
    $ls = $conn->query("SELECT Item_Name, Quantity FROM tbl_inventory WHERE Quantity > 0 AND Quantity < 5 ORDER BY Quantity ASC, Item_Name ASC LIMIT 5");
    if ($ls) {
      while ($r = $ls->fetch_assoc()) {
        $lowStockItems[] = [
          'name' => (string)($r['Item_Name'] ?? 'Unknown'),
          'qty'  => (int)($r['Quantity'] ?? 0),
        ];
      }
    }
  }

  // Pending approvals (next 10 upcoming)
  $pa = $conn->prepare("SELECT a.Appointment_Id, a.Date, a.Time, a.`Procedure`, COALESCE(p.First_name,'') fn, COALESCE(p.Last_name,'') ln FROM tbl_appointments a LEFT JOIN tbl_patient p ON a.Email = p.Email WHERE a.Status = 'Pending' AND a.Date >= CURDATE() ORDER BY a.Date ASC, a.Time ASC LIMIT 10");
  if ($pa) {
    if ($pa->execute()) {
      $pres = $pa->get_result();
      while ($row = $pres->fetch_assoc()) { $pendingApprovals[] = $row; }
    }
    $pa->close();
  }
}

// Handle Dentist Management form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_dentist']) && isset($conn)) {
  $d_name  = trim($_POST['dentist_name'] ?? '');
  $d_email = trim($_POST['dentist_email'] ?? '');
  $d_pass  = trim($_POST['dentist_password'] ?? '');
  $d_spec  = trim($_POST['dentist_specialization'] ?? 'General Dentistry');
  $d_phone = trim($_POST['dentist_phone'] ?? '');

  if ($d_name === '' || $d_email === '' || $d_pass === '') {
    $dentistAlert = '<div class="alert alert-danger mb-3">Full Name, Email and Password are required.</div>';
  } else if (!filter_var($d_email, FILTER_VALIDATE_EMAIL)) {
    $dentistAlert = '<div class="alert alert-danger mb-3">Please enter a valid email address.</div>';
  } else {
    // Ensure table exists (columns may vary by install)
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_dentist (
      Dentist_id INT AUTO_INCREMENT PRIMARY KEY,
      Name VARCHAR(255) NOT NULL,
      Email VARCHAR(255) UNIQUE,
      Password VARCHAR(255),
      Specialization VARCHAR(255) DEFAULT 'General Dentistry',
      Phone VARCHAR(64),
      is_active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB");

    $hash = password_hash($d_pass, PASSWORD_BCRYPT);
    if ($stmt = $conn->prepare("INSERT INTO tbl_dentist (Name, Email, Password, Specialization, Phone, is_active) VALUES (?, ?, ?, ?, ?, 1)")) {
      $stmt->bind_param('sssss', $d_name, $d_email, $hash, $d_spec, $d_phone);
      if ($stmt->execute()) {
        $dentistAlert = '<div class="alert alert-success mb-3">Dentist added successfully.</div>';
      } else {
        $dentistAlert = '<div class="alert alert-danger mb-3">Failed to add dentist. The email might already be in use.</div>';
      }
      $stmt->close();
    } else {
      $dentistAlert = '<div class="alert alert-danger mb-3">Failed to prepare query.</div>';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Admin Dashboard - Miles Dental Clinic</title>
  <?php include 'header.php'; ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" integrity="sha512-0ouX9p2lJ5gq5b4cYtq2w0Q3mQO0cRrJ+J3Wk9z8uHk5m8Q8uM2b3bq3rJ5uKk2aZ8tq3m9oQkVxQ8wV3b8hWg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body { 
      font-family: Arial, sans-serif; 
      margin: 0; 
      padding: 0; 
      overflow: auto; /* allow page to scroll on smaller screens */
      height: auto;
      background-color: #f3f8ff; /* light blue background */
    }
    .sidebar { 
      height: 100vh; 
      background-color: #343a40; 
      color: white; 
      padding: 15px; 
      min-width: 250px;
    }
    .sidebar a { 
      color: white; 
      text-decoration: none; 
      display: block; 
      margin: 8px 0; 
      padding: 8px 12px;
      border-radius: 4px;
      transition: background-color 0.2s;
    }
    .sidebar a:hover { 
      background-color: #495057; 
      text-decoration: none;
    }
    .content-wrapper {
      flex: 1;
      height: 100vh;
      overflow-y: auto;
      background-color: #f3f8ff; /* light blue background */
    }
    .content { padding: 16px; height: 100%; margin: 0; color: #111827; }
    .card { 
      margin-bottom: 16px; 
      border: 1px solid #e5e7eb;
      background: #ffffff;
      color: #111827;
    }
    .card-body {
      padding: 1rem;
    }
    .row.g-3 {
      --bs-gutter-x: 1rem;
      --bs-gutter-y: 1rem;
      margin: 0;
    }
    /* Colored Stat Cards */
    .stats-card {
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 10px 20px rgba(0,0,0,0.08);
      border: none;
      color: #fff;
    }
    .stats-card .card-body { padding: 18px 20px; }
    .stats-card .card-title { color: rgba(255,255,255,0.9); font-weight: 600; }
    .stats-card .fs-5 { font-size: 1.6rem !important; font-weight: 700; color: #fff; }

    .stats-card.patients { background: linear-gradient(135deg,#3b82f6,#2563eb); }
    .stats-card.appointments { background: linear-gradient(135deg,#8b5cf6,#7c3aed); }
    .stats-card.approvals { background: linear-gradient(135deg,#f59e0b,#ea580c); }

    /* Inventory card states */
    .stats-card.inventory.in-stock { background: linear-gradient(135deg,#10b981,#059669); }
    .stats-card.inventory.low-stock { background: linear-gradient(135deg,#fbbf24,#f59e0b); color:#1f2937; }
    .stats-card.inventory.low-stock .card-title { color:#1f2937; }
    .stats-card.inventory.low-stock .fs-5 { color:#111827; }
    .stats-card.inventory.out-of-stock { background: linear-gradient(135deg,#ef4444,#dc2626); }

    /* Inventory list inside colored card */
    .inventory-list { list-style: none; padding-left: 0; margin: 0; }
    .inventory-list li { color: rgba(255,255,255,0.95); }
    #chartWrap { height: 320px; margin-top: 8px; background: #ffffff; border-radius: 10px; padding: 16px; border: 1px solid #e5e7eb; }
    .app-layout {
      min-height: 100vh;
      width: 100vw;
      margin: 0;
      padding: 0;
    }
    html {
      height: 100%;
      margin: 0;
      padding: 0;
    }
    @media (max-width: 768px) {
      .content-wrapper { height: auto; }
    }
    /* Admin notifications bell (inline in header) */
    .notif-wrap { position: relative; }
    #adminNotifBell { border:1px solid #e5e7eb; background:#ffffff; color:#111827; width:40px; height:40px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; box-shadow: 0 2px 8px rgba(0,0,0,0.06); cursor: pointer; position: relative; }
    #adminNotifBell i { font-size:18px; }
    #adminNotifBell .badge { position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; border-radius:999px; min-width:18px; height:18px; display:none; align-items:center; justify-content:center; font-size:11px; padding:0 5px; font-weight:700; box-shadow:0 0 0 2px #fff; }
    #adminNotifBell.has { border-color:#fecaca; box-shadow:0 4px 14px rgba(239,68,68,0.18); }
    /* Admin notifications popover */
    #adminNotifPanel { position: absolute; top: 46px; right: 0; z-index: 999; width: 320px; max-height: 60vh; overflow: auto; display: none; background:#ffffff; color:#111827; border:1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 12px 28px rgba(0,0,0,0.18); }
    #adminNotifPanel .panel-hdr { display:flex; align-items:center; justify-content: space-between; gap:8px; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-weight:600; }
    #adminNotifPanel .panel-body { padding: 8px 0; }
  </style>
</head>
<body>
<div class="app-layout d-flex">
  <?php if (file_exists($baseDir . '/admin_sidebar.php')) { include 'admin_sidebar.php'; } else { ?>
  <div class="sidebar">
    <h3>Myles Dental</h3>
    <a href="/dental_clinic/admin_dashboard.php">Dashboard</a>
    <a href="/dental_clinic/appointment.php">Appointments</a>
    <a href="/dental_clinic/admin_messages.php">Messages</a>
    <a href="/dental_clinic/admin_inventory.php">Inventory</a>
    <a href="/dental_clinic/preferences.php">Settings</a>
    <a href="logout.php">Logout</a>
  </div>
  <?php } ?>

  <div class="content-wrapper">
    <div class="content">
      <div class="card mb-3" style="border-radius:14px; border:1px solid #e5e7eb;">
        <div class="card-body d-flex align-items-center justify-content-between" style="padding:18px 20px; position:relative;">
          <div class="d-flex align-items-center gap-3">
            <h4 class="mb-1" style="font-weight:700;color:#111827;">
              Welcome to <?= htmlspecialchars($clinicBranding['name'] ?? 'Miles Dental Clinic') ?>
            </h4>
            <?php if (!empty($clinicBranding['address'])): ?>
              <div class="text-muted" style="font-size:14px;">Admin Dashboard</div>
            <?php else: ?>
              <div class="text-muted" style="font-size:14px;"></div>
            <?php endif; ?>
          </div>
          <div class="d-flex align-items-center gap-2">
            <div class="notif-wrap">
              <button id="adminNotifBell" type="button" title="Notifications" aria-label="Notifications">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                  <path d="M8 9a4 4 0 118 0c0 3 1 4 2 5H6c1-1 2-2 2-5z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                  <path d="M10 19a2 2 0 004 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="badge" id="adminNotifBadge">0</span>
              </button>
              <div id="adminNotifPanel" role="dialog" aria-modal="false" aria-labelledby="notifHdr" aria-hidden="true">
                <div class="panel-hdr">
                  <span id="notifHdr">Notifications</span>
                  <div>
                    <button id="markAllReadBtn" class="btn btn-sm btn-outline-secondary">Mark all read</button>
                    <button id="closeNotifPanel" class="btn btn-sm btn-light">Close</button>
                  </div>
                </div>
                <div class="panel-body" id="notifList">
                  <div class="empty">No notifications</div>
                </div>
              </div>
            </div>
            <?php if (!empty($clinicBranding['logo_url'])): ?>
              <img src="<?= htmlspecialchars($clinicBranding['logo_url']) ?>" alt="Clinic Logo" style="height:40px;width:40px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;">
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-md-3">
          <a href="admin_appointments.php" class="text-decoration-none d-block" style="color: inherit;">
          <div class="stats-card patients">
            <div class="card-body">
              <h5 class="card-title mb-1">Total Patients</h5>
              <div class="fs-5"><?= (int)$patientCount ?></div>
            </div>
          </div>
          </a>
        </div>
        <div class="col-md-3">
          <a href="admin_appointments.php" class="text-decoration-none d-block" style="color: inherit;">
          <div class="stats-card appointments">
            <div class="card-body">
              <h5 class="card-title mb-1">Appointments Today</h5>
              <div class="fs-5"><?= (int)$apptCount ?></div>
            </div>
          </div>
          </a>
        </div>
        <div class="col-md-3">
          <a href="admin_appointments.php?show_completed=0&status=Pending" class="text-decoration-none d-block" style="color: inherit;">
          <div class="stats-card approvals">
            <div class="card-body">
              <h5 class="card-title mb-1">Pending Approvals</h5>
              <div class="fs-5"><?= count($pendingApprovals) ?></div>
            </div>
          </div>
          </a>
        </div>
        <div class="col-md-3">
          <a href="admin_inventory.php" class="text-decoration-none d-block" style="color: inherit;">
          <div class="stats-card inventory <?= ($invCounts['out'] ?? 0) > 0 ? 'out-of-stock' : ( (($invCounts['low'] ?? 0) > 0 ? 'low-stock' : 'in-stock' ) ) ?>">
            <div class="card-body">
              <h5 class="card-title mb-2">Low Stock Items</h5>
              <?php if (empty($lowStockItems)): ?>
                <div style="color: rgba(255,255,255,0.85);">No low-stock items.</div>
              <?php else: ?>
                <ul class="inventory-list">
                  <?php foreach ($lowStockItems as $it): ?>
                    <li class="d-flex justify-content-between align-items-center mb-1">
                      <span class="text-truncate" style="max-width: 70%;">
                        <?= htmlspecialchars($it['name']) ?>
                      </span>
                      <?php $qty = (int)$it['qty']; ?>
                      <span class="badge <?= $qty === 0 ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= $qty ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
          </a>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-8">
          <div id="chartWrap"><canvas id="appointmentsChart"></canvas></div>
        </div>
        <div class="col-lg-4">
          <div class="card">
            <div class="card-body">
              <h5 class="mb-3">Top Treatments (This Month)</h5>
              <div style="height: 250px; position: relative;">
                <canvas id="topTreatments"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <h5 class="mb-3">Approval Requests</h5>
          <?php if (empty($pendingApprovals)): ?>
            <div class="text-muted">No pending approvals.</div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-dark table-striped align-middle">
              <thead><tr><th>Patient</th><th>Procedure</th><th>Date</th><th>Time</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($pendingApprovals as $p): ?>
                  <tr>
                    <td><?= htmlspecialchars(trim(($p['fn'] ?? '') . ' ' . ($p['ln'] ?? '')) ?: 'Unknown') ?></td>
                    <td><?= htmlspecialchars($p['Procedure']) ?></td>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($p['Date']))) ?></td>
                    <td><?= htmlspecialchars(date('g:i A', strtotime($p['Time']))) ?></td>
                    <td>
                      <form method="post" action="admin_appointments.php" class="d-inline">
                        <input type="hidden" name="confirm_id" value="<?= (int)$p['Appointment_Id'] ?>" />
                        <button class="btn btn-sm btn-success">Confirm</button>
                      </form>
                      <form method="post" action="admin_appointments.php" class="d-inline">
                        <input type="hidden" name="cancel_id" value="<?= (int)$p['Appointment_Id'] ?>" />
                        <button class="btn btn-sm btn-outline-danger">Cancel</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const apptData = <?= json_encode(array_values($weeklyAppts)) ?>;
  const ctx = document.getElementById('appointmentsChart').getContext('2d');
  const maxVal = Math.max(1, ...apptData);
  
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
      datasets: [{ 
        label: 'Appointments', 
        data: apptData, 
        backgroundColor: 'rgba(59,130,246,0.8)',
        borderColor: 'rgba(37,99,235,1)',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          suggestedMin: 0,
          suggestedMax: 30,
          ticks: { 
            stepSize: 5, 
            precision: 0,
            font: {
              size: 12
            }
          },
          grid: {
            color: 'rgba(0,0,0,0.1)'
          }
        },
        x: {
          ticks: {
            font: {
              size: 12
            }
          },
          grid: {
            display: false
          }
        }
      }
    }
  });

  // Top Treatments Pie
  const ttCtx = document.getElementById('topTreatments');
  if (ttCtx) {
    const ttData = <?php
      $labels = array_keys($procedureStats);
      $data = array_values($procedureStats);
      echo json_encode(['labels'=>$labels,'data'=>$data]);
    ?>;
    new Chart(ttCtx, {
      type: 'doughnut',
      data: { labels: ttData.labels, datasets: [{ data: ttData.data, backgroundColor: ['#60a5fa','#34d399','#fbbf24','#f87171','#a78bfa','#f472b6','#22d3ee'] }] },
      options: { 
        responsive: true,
        maintainAspectRatio: false,
        plugins: { 
          legend: { 
            position: 'bottom',
            align: 'start',
            labels: {
              usePointStyle: true,
              boxWidth: 10,
              padding: 12,
              font: { size: 12 },
              color: '#111827'
            }
          },
          tooltip: {
            backgroundColor: 'rgba(17,24,39,0.9)',
            titleColor: '#fff',
            bodyColor: '#fff'
          }
        },
        elements: {
          arc: {
            borderWidth: 2
          }
        }
      }
    });
  }
</script>

<script>
  (function(){
    const bell = document.getElementById('adminNotifBell');
    const badge = document.getElementById('adminNotifBadge');
    const panel = document.getElementById('adminNotifPanel');
    const list = document.getElementById('notifList');
    const btnClose = document.getElementById('closeNotifPanel');
    const btnMarkAll = document.getElementById('markAllReadBtn');

    if (!bell || !panel) return;

    function refreshAdminNotif(){
      fetch('check_notifications.php')
        .then(r=>r.json())
        .then(data=>{
          const c = (data && typeof data.count!== 'undefined') ? parseInt(data.count,10) : 0;
          if (c>0){
            badge.style.display='inline-flex';
            badge.textContent = String(Math.min(c,99));
            bell.classList.add('has');
          } else {
            badge.style.display='none';
            bell.classList.remove('has');
          }
        })
        .catch(()=>{});
    }

    function fetchRecent(){
      fetch('check_notifications.php?action=get_recent_notifications')
        .then(r=>r.json())
        .then(data=>{
          list.innerHTML = '';
          const items = (data && data.success && Array.isArray(data.items)) ? data.items : [];
          if (items.length===0){
            list.innerHTML = '<div class="empty">No notifications</div>';
            return;
          }
          items.forEach(n => {
            const div = document.createElement('div');
            div.className = 'notif-item d-flex flex-column p-2 border-bottom';
            const when = n.Created_At ? new Date(n.Created_At.replace(' ','T')).toLocaleString() : '';
            const top = document.createElement('div');
            top.className='d-flex align-items-start justify-content-between gap-2';
            const msg = document.createElement('div');
            msg.innerText = n.Message || '';
            const del = document.createElement('button');
            del.className='btn btn-sm btn-outline-danger';
            del.textContent='Delete';
            del.addEventListener('click', function(e){
              e.stopPropagation();
              const fd = new FormData();
              fd.set('id', String(n.Id));
              fetch('check_notifications.php?action=delete_notification', { method:'POST', body: fd })
                .then(r=>r.json())
                .then(()=>{ refreshAdminNotif(); fetchRecent(); })
                .catch(()=>{});
            });
            top.appendChild(msg);
            top.appendChild(del);
            const sm = document.createElement('small');
            sm.className = 'text-muted';
            sm.innerText = when;
            div.appendChild(top);
            div.appendChild(sm);
            list.appendChild(div);
          });
        })
        .catch(()=>{ list.innerHTML = '<div class="empty">Failed to load notifications</div>'; });
    }

    function openPanel(){ panel.style.display = 'block'; fetchRecent(); }
    function closePanel(){ panel.style.display = 'none'; }

    bell.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); if (panel.style.display==='block'){ closePanel(); } else { openPanel(); } });
    if (btnClose) btnClose.addEventListener('click', function(){ closePanel(); });
    document.addEventListener('click', function(e){ if (!panel.contains(e.target) && e.target !== bell){ closePanel(); } });
    if (btnMarkAll) btnMarkAll.addEventListener('click', function(){ fetch('check_notifications.php?action=mark_all_read').then(r=>r.json()).then(()=>{ refreshAdminNotif(); fetchRecent(); }); });

    refreshAdminNotif();
    setInterval(refreshAdminNotif, 20000);
  })();
</script>
   
</body>
</html>
