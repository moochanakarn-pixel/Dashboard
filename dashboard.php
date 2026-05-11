<?php
require __DIR__ . '/dashboard_config.php';

$cacheDir = __DIR__ . '/cache';
$slotFile = $cacheDir . '/active_slots.json';
$maxSlots = 5;
$slotTtl  = 90;

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}

$cookiePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
$token = $_COOKIE['_ds'] ?? '';

$lock = fopen($slotFile . '.lock', 'c');
flock($lock, LOCK_EX);

$slots = [];
if (is_file($slotFile)) {
    $slots = json_decode(@file_get_contents($slotFile), true) ?? [];
}
$now = time();
foreach ($slots as $t => $ts) {
    if ($now - $ts >= $slotTtl) unset($slots[$t]);
}

$granted = false;
if ($token && isset($slots[$token])) {
    $slots[$token] = $now;
    $granted = true;
} elseif (count($slots) < $maxSlots) {
    $token = bin2hex(random_bytes(8));
    $slots[$token] = $now;
    $granted = true;
    setcookie('_ds', $token, 0, $cookiePath);
}

@file_put_contents($slotFile, json_encode($slots), LOCK_EX);
flock($lock, LOCK_UN);
fclose($lock);

if (!$granted) {
    $activeCount = count($slots);
    http_response_code(503);
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ระบบมีผู้ใช้งานเต็ม</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#0b1220;color:#ecf2ff;display:flex;align-items:center;justify-content:center;min-height:100vh}
.box{text-align:center;padding:48px 40px;background:#121a2b;border:1px solid rgba(255,255,255,.08);border-radius:22px;max-width:420px;width:90%}
h1{font-size:26px;margin:0 0 8px}
.count{font-size:52px;font-weight:800;color:#6ea8fe;margin:16px 0}
p{color:#9fb0d0;margin:0 0 28px;line-height:1.6}
button{background:linear-gradient(135deg,#6ea8fe,#8d8cff);color:#fff;border:none;border-radius:16px;padding:14px 32px;font-size:16px;font-weight:700;cursor:pointer}
</style>
</head>
<body>
<div class="box">
    <h1>ระบบมีผู้ใช้งานเต็ม</h1>
    <div class="count"><?php echo (int)$activeCount; ?> / <?php echo $maxSlots; ?></div>
    <p>มีผู้ใช้งานครบจำนวนแล้ว<br>กรุณารอสักครู่แล้วลองใหม่อีกครั้ง</p>
    <button onclick="location.reload()">ลองใหม่</button>
</div>
</body>
</html><?php
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales Dashboard</title>
    <meta name="description" content="แดชบอร์ดยอดขาย สินค้าขายดี สรุปการชำระเงิน และสรุปการใช้ส่วนลดแบบเรียลไทม์">
    <meta property="og:title" content="Sales Dashboard">
    <meta property="og:description" content="แดชบอร์ดยอดขาย สินค้าขายดี สรุปการชำระเงิน และสรุปการใช้ส่วนลดแบบเรียลไทม์">
    <meta property="og:type" content="website">
    <!-- PWA -->
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="dashboardicon.png">
    <link rel="apple-touch-icon" href="dashboardicon.png">
    <meta name="theme-color" content="#0b1220">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Dashboard">
    <meta name="mobile-web-app-capable" content="yes">
    <style>
        :root{
            --bg:#0b1220; --bg2:#0d1526; --card:#121a2b; --card2:#182235; --line:rgba(255,255,255,.08);
            --text:#ecf2ff; --muted:#9fb0d0; --accent:#6ea8fe; --accent2:#82e7c7; --danger:#ff8a8a;
            --shadow:0 20px 60px rgba(0,0,0,.28); --radius:22px; --pill:rgba(255,255,255,.05);
            --hero1:rgba(110,168,254,.18); --hero2:rgba(130,231,199,.10); --row:rgba(255,255,255,.02);
            --track:rgba(255,255,255,.06); --badgebg:rgba(130,231,199,.12); --badgetext:#a8f2dd; --badgeline:rgba(130,231,199,.18);
        }
        body[data-theme="light"]{
            --bg:#edf3ff; --bg2:#dfe9ff; --card:#ffffff; --card2:#ffffff; --line:rgba(35,64,126,.10);
            --text:#18233d; --muted:#617292; --accent:#3d7bfd; --accent2:#2fc59d; --danger:#d63e55;
            --shadow:0 18px 45px rgba(48,78,141,.12); --pill:rgba(61,123,253,.06);
            --hero1:rgba(61,123,253,.10); --hero2:rgba(47,197,157,.08); --row:rgba(61,123,253,.04);
            --track:rgba(61,123,253,.08); --badgebg:rgba(47,197,157,.10); --badgetext:#0e8a67; --badgeline:rgba(47,197,157,.20);
        }
        *{box-sizing:border-box} body{margin:0;font-family:Segoe UI,Tahoma,Arial,sans-serif;background:radial-gradient(circle at top right,var(--hero1),transparent 30%),radial-gradient(circle at top left,rgba(255,122,182,.12),transparent 26%),linear-gradient(180deg,var(--bg) 0%,var(--bg2) 100%);color:var(--text);transition:background .2s ease,color .2s ease}
        .container{max-width:1500px;margin:0 auto;padding:24px}.card{background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.02));border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
        .hero{padding:24px;display:grid;grid-template-columns:1.4fr auto;gap:16px;align-items:center;background:linear-gradient(135deg,var(--hero1),var(--hero2))}
        .hero h1{margin:0 0 8px;font-size:34px}.hero p{margin:0;color:var(--muted);font-size:16px}.hero-controls{display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
        .theme-wrap{display:flex;gap:8px;align-items:center}.theme-btn{width:38px;height:38px;border-radius:999px;border:2px solid transparent;cursor:pointer;box-shadow:0 6px 18px rgba(0,0,0,.12)}.theme-btn.active{border-color:rgba(255,255,255,.85);transform:scale(1.05)} body[data-theme="light"] .theme-btn.active{border-color:rgba(24,35,61,.75)} .theme-dark{background:linear-gradient(135deg,#6ea8fe,#82e7c7)} .theme-light{background:linear-gradient(135deg,#ffffff,#7aa5ff)}
        .control{background:var(--pill);border:1px solid var(--line);color:var(--text);border-radius:16px;padding:12px 14px;min-height:48px} input[type=date]{color-scheme:dark} body[data-theme="light"] input[type=date]{color-scheme:light}
        button.action{background:linear-gradient(135deg,var(--accent),#8d8cff);color:#fff;border:none;border-radius:16px;padding:12px 18px;font-weight:700;cursor:pointer}
        .meta{margin-top:16px;display:flex;gap:12px;flex-wrap:wrap;color:var(--muted)} .pill{padding:8px 12px;border-radius:999px;background:var(--pill);border:1px solid var(--line)}
        .grid-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin-top:20px}.stat{padding:22px}.label{color:var(--muted);font-size:14px;margin-bottom:10px}.value{font-size:34px;font-weight:800}.sub{margin-top:10px;color:var(--muted);font-size:13px}
        .grid-main{display:grid;grid-template-columns:1.08fr .92fr;gap:16px;margin-top:16px}.grid-bottom{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}.section{padding:20px}.section h2{margin:0 0 16px;font-size:20px}
        .bars{display:grid;gap:12px}.bar-row{display:grid;grid-template-columns:170px 1fr auto;gap:12px;align-items:center}.track{height:12px;background:var(--track);border-radius:999px;overflow:hidden;border:1px solid rgba(255,255,255,.03)}.fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--accent),var(--accent2))}
        .bar-label,.bar-value{font-size:14px}.bar-value{color:var(--muted);white-space:nowrap}.table-wrap{overflow:auto} table{width:100%;border-collapse:collapse} th,td{padding:14px 12px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap} th{color:var(--muted);font-size:13px;font-weight:600} td{font-size:14px} tr:hover td{background:var(--row)}
        .badge{display:inline-flex;padding:7px 12px;border-radius:999px;background:var(--badgebg);color:var(--badgetext);border:1px solid var(--badgeline);font-size:12px;font-weight:700}
        .empty{padding:18px;border-radius:18px;background:var(--pill);color:var(--muted);text-align:center}.error-box{display:none;margin-top:16px;padding:14px 16px;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(255,99,99,.12);color:var(--danger);white-space:pre-wrap}.rank{font-weight:800;color:var(--accent)}.footer-note{margin-top:14px;color:var(--muted);font-size:12px;text-align:right}.text-right{text-align:right}
        @media (max-width:1280px){.grid-cards{grid-template-columns:repeat(3,1fr)}} @media (max-width:1200px){.grid-main,.grid-bottom{grid-template-columns:1fr}.hero{grid-template-columns:1fr}.hero-controls{justify-content:flex-start}} @media (max-width:720px){.container{padding:14px}.grid-cards{grid-template-columns:1fr}.hero h1{font-size:28px}.value{font-size:30px}.bar-row{grid-template-columns:1fr}}
    </style>
</head>
<body data-theme="dark">
<div class="container">
    <div class="hero card">
        <div>
            <h1>Sales Dashboard</h1>
            <div class="meta">
            </div>
        </div>
        <div class="hero-controls">
            <div class="theme-wrap">
                <button class="theme-btn theme-dark active" data-theme="dark" title="Dark"></button>
                <button class="theme-btn theme-light" data-theme="light" title="Light"></button>
            </div>
            <input class="control" type="date" id="dateInput" value="<?php echo h($date); ?>">
            <button class="action" id="reloadBtn">รีโหลดข้อมูล</button>
        </div>
    </div>

    <div class="error-box" id="errorBox"></div>

    <div class="grid-cards">
        <div class="card stat"><div class="label">ยอดขายรวม</div><div class="value" id="salesTotal">-</div><div class="sub">จากบิลที่ชำระแล้ว</div></div>
        <div class="card stat"><div class="label">จำนวนบิล</div><div class="value" id="billCount">-</div><div class="sub">บิลที่ปิดการขายแล้ว</div></div>
        <div class="card stat"><div class="label">ค่าเฉลี่ยต่อบิล</div><div class="value" id="avgBill">-</div><div class="sub">ยอดเฉลี่ยต่อใบเสร็จ</div></div>
        <div class="card stat"><div class="label">จำนวนลูกค้า</div><div class="value" id="guestCount">-</div><div class="sub">รวมจาก NoCustomer</div></div>
        <div class="card stat"><div class="label">ส่วนลดรวม</div><div class="value" id="discountTotal">-</div><div class="sub" id="discountBillCount">จากบิลที่มีส่วนลด</div></div>
    </div>

    <div class="grid-main">
        <div class="card section">
            <h2>ยอดขายตามช่วงเวลา</h2>
            <div class="bars" id="hourlyBars"><div class="empty">กำลังโหลดข้อมูล...</div></div>
        </div>
        <div class="card section">
            <h2>ยอดขายตามประเภทการขาย</h2>
            <div class="bars" id="saleModeBars"><div class="empty">กำลังโหลดข้อมูล...</div></div>
        </div>
    </div>

    <div class="grid-bottom">
        <div class="card section">
            <h2>สินค้าขายดี</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>สินค้า</th><th>จำนวน</th><th>ยอดขาย</th></tr></thead>
                    <tbody id="topProductsBody"><tr><td colspan="4" class="empty">กำลังโหลดข้อมูล...</td></tr></tbody>
                </table>
            </div>
        </div>
        <div class="card section">
            <h2>สรุปการชำระเงิน</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ประเภท</th><th>จำนวนครั้ง</th><th>จำนวนบิล</th><th>ยอดรวม</th></tr></thead>
                    <tbody id="paymentTableBody"><tr><td colspan="4" class="empty">กำลังโหลดข้อมูล...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card section" style="margin-top:16px;">
        <h2>สรุปการใช้ส่วนลด</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ชื่อส่วนลด</th><th>จำนวนบิล</th><th>ยอดส่วนลด</th><th>% เทียบยอดขาย</th></tr></thead>
                <tbody id="discountTableBody"><tr><td colspan="4" class="empty">กำลังโหลดข้อมูล...</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="card section" style="margin-top:16px;">
        <h2>บิลล่าสุดที่ชำระแล้ว</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>เวลา</th><th>เลขบิล</th><th>โต๊ะ / ชื่อบิล</th><th>ประเภท</th><th>ลูกค้า</th><th>สถานะ</th><th>ยอดชำระ</th></tr></thead>
                <tbody id="recentBillsBody"><tr><td colspan="7" class="empty">กำลังโหลดข้อมูล...</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="footer-note" id="footerNote">Auto refresh กำลังเตรียมทำงาน...</div>
</div>
<script>
const dateInput = document.getElementById('dateInput');
const reloadBtn = document.getElementById('reloadBtn');
const themeButtons = document.querySelectorAll('.theme-btn');
const bodyEl = document.body;
const themeKey = 'sales_dashboard_theme';
const refreshMs = <?php echo (int)$DASHBOARD_REFRESH_MS; ?>;
const requestTimeoutMs = 15000;
const errorBox = document.getElementById('errorBox');
const footerNote = document.getElementById('footerNote');
let isLoading = false;
let autoRefreshTimer = null;
let activeController = null;
const slotToken = <?php echo json_encode($token); ?>;

function money(n){return new Intl.NumberFormat('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(n||0));}
function intfmt(n){return new Intl.NumberFormat('th-TH',{maximumFractionDigits:0}).format(Number(n||0));}
function qtyfmt(n){return new Intl.NumberFormat('th-TH',{minimumFractionDigits:0,maximumFractionDigits:2}).format(Number(n||0));}
function pctfmt(n){return new Intl.NumberFormat('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(n||0));}
function escapeHtml(v){return String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));}
function renderBars(el, rows, valueKey, labelKey, formatter, emptyText='ไม่มีข้อมูล'){ if(!rows||!rows.length){el.innerHTML=`<div class="empty">${emptyText}</div>`;return;} const max=Math.max(...rows.map(r=>Number(r[valueKey]||0)),1); el.innerHTML=rows.map(r=>{const val=Number(r[valueKey]||0); const width=Math.max((val/max)*100,4); return `<div class="bar-row"><div class="bar-label">${escapeHtml(r[labelKey]??'-')}</div><div class="track"><div class="fill" style="width:${width}%"></div></div><div class="bar-value">${formatter(val)}</div></div>`;}).join(''); }
function renderRecentBills(rows){const body=document.getElementById('recentBillsBody'); if(!rows||!rows.length){body.innerHTML='<tr><td colspan="7" class="empty">ไม่พบบิลที่ชำระแล้ว</td></tr>';return;} body.innerHTML=rows.map(r=>{const when=r.paid_time||r.close_time||'-'; const bill=r.receipt_id?('#'+r.receipt_id):('T'+r.transaction_id+'-'+r.computer_id); const title=[r.table_name,r.transaction_name,r.queue_name].filter(Boolean).join(' / ')||'-'; return `<tr><td>${escapeHtml(when)}</td><td>${escapeHtml(bill)}</td><td>${escapeHtml(title)}</td><td>${escapeHtml(r.sale_mode_name||'-')}</td><td>${intfmt(r.no_customer||0)}</td><td><span class="badge">${escapeHtml(r.bill_status||'ชำระแล้ว')}</span></td><td>${money(r.receipt_pay_price)} ฿</td></tr>`;}).join('');}
function renderTopProducts(rows){const body=document.getElementById('topProductsBody'); if(!rows||!rows.length){body.innerHTML='<tr><td colspan="4" class="empty">ยังไม่มีข้อมูลสินค้าขายดี</td></tr>';return;} body.innerHTML=rows.map((r,idx)=>`<tr><td class="rank">#${idx+1}</td><td>${escapeHtml(r.product_name||'-')}</td><td>${qtyfmt(r.qty_sold)}</td><td>${money(r.total_sales)} ฿</td></tr>`).join('');}
function renderPaymentTable(rows){const body=document.getElementById('paymentTableBody'); if(!rows||!rows.length){body.innerHTML='<tr><td colspan="4" class="empty">ยังไม่พบข้อมูลประเภทการชำระเงิน</td></tr>';return;} body.innerHTML=rows.map(r=>`<tr><td>${escapeHtml(r.pay_type_name||'-')}</td><td>${intfmt(r.payment_rows||0)}</td><td>${intfmt(r.bill_count||0)}</td><td>${money(r.total_amount)} ฿</td></tr>`).join('');}
function renderDiscountTable(rows){const body=document.getElementById('discountTableBody'); if(!rows||!rows.length){body.innerHTML='<tr><td colspan="4" class="empty">ยังไม่พบข้อมูลส่วนลด</td></tr>';return;} body.innerHTML=rows.map(r=>`<tr><td>${escapeHtml(r.discount_name||'-')}</td><td>${intfmt(r.bill_count||0)}</td><td>${money(r.total_discount)} ฿</td><td>${pctfmt(r.pct_of_sales)}%</td></tr>`).join('');}
function applyTheme(theme){const safeTheme=(theme==='light')?'light':'dark'; bodyEl.setAttribute('data-theme',safeTheme); localStorage.setItem(themeKey,safeTheme); themeButtons.forEach(btn=>btn.classList.toggle('active',btn.dataset.theme===safeTheme));}
function showError(msg){if(msg){errorBox.style.display='block'; errorBox.textContent=msg;} else {errorBox.style.display='none'; errorBox.textContent='';}}
function isToday(dateStr){return dateStr === new Date().toISOString().slice(0,10);}
function shouldAutoRefresh(){const selectedDate = dateInput.value || new Date().toISOString().slice(0,10); return !document.hidden && isToday(selectedDate);}
function setControlsLoading(loading){reloadBtn.disabled = loading; dateInput.disabled = loading; reloadBtn.style.opacity = loading ? '0.7' : '1'; reloadBtn.style.cursor = loading ? 'wait' : 'pointer';}
function updateFooterNote(){const selectedDate = dateInput.value || new Date().toISOString().slice(0,10); if(document.hidden){footerNote.textContent = 'หยุด auto refresh ชั่วคราว เพราะแท็บนี้ไม่ได้เปิดอยู่'; return;} if(!isToday(selectedDate)){footerNote.textContent = 'ปิด auto refresh อัตโนมัติ เพราะกำลังดูข้อมูลย้อนหลัง'; return;} footerNote.textContent = `auto refresh ทุก ${Math.round(refreshMs/1000)} วินาที สำหรับข้อมูลวันนี้`;}
function stopAutoRefresh(){ if(autoRefreshTimer){ clearInterval(autoRefreshTimer); autoRefreshTimer = null; } updateFooterNote(); }
function startAutoRefresh(){ stopAutoRefresh(); if(!shouldAutoRefresh()) return; autoRefreshTimer = setInterval(() => { loadDashboard(); }, refreshMs); updateFooterNote(); }
async function fetchWithTimeout(url, options = {}, timeout = requestTimeoutMs){
  if(activeController){ activeController.abort(); }
  const controller = new AbortController();
  activeController = controller;
  const timer = setTimeout(() => controller.abort(), timeout);
  try {
    return await fetch(url, { ...options, signal: controller.signal });
  } finally {
    clearTimeout(timer);
    if(activeController === controller){ activeController = null; }
  }
}
const savedTheme=localStorage.getItem(themeKey)||'dark'; applyTheme(savedTheme); themeButtons.forEach(btn=>btn.addEventListener('click',()=>applyTheme(btn.dataset.theme)));
async function loadDashboard(forceRefresh = false){
  if(isLoading) return;
  isLoading = true;
  setControlsLoading(true);
  showError('');
  try{
    const date=dateInput.value||new Date().toISOString().slice(0,10);
    const url='api_dashboard.php?date='+encodeURIComponent(date)+(forceRefresh?'&force=1':'')+'&_='+Date.now();
    const res=await fetchWithTimeout(url,{cache:'no-store'});
    const data=await res.json();
    if(!res.ok){ throw new Error(data.error||'โหลดข้อมูลไม่สำเร็จ'); }
    showError(data.error||'');
    document.getElementById('salesTotal').textContent=money(data.summary.sales_total)+' ฿';
    document.getElementById('billCount').textContent=intfmt(data.summary.bill_count);
    document.getElementById('avgBill').textContent=money(data.summary.avg_bill)+' ฿';
    document.getElementById('guestCount').textContent=intfmt(data.summary.guest_count);
    document.getElementById('discountTotal').textContent=money(data.summary.discount_total)+' ฿';
    document.getElementById('discountBillCount').textContent='จาก '+intfmt(data.summary.discount_bill_count)+' บิล';
    renderBars(document.getElementById('hourlyBars'), data.hourly, 'total_sales', 'hour_label', v=>money(v)+' ฿', 'ยังไม่มียอดขายในวันนี้');
    renderBars(document.getElementById('saleModeBars'), data.sale_modes, 'total_sales', 'sale_mode_name', v=>money(v)+' ฿', 'ยังไม่พบข้อมูลประเภทการขาย');
    renderTopProducts(data.top_products);
    renderPaymentTable(data.payment_types);
    renderDiscountTable(data.discount_summary);
    renderRecentBills(data.recent_bills);
  } catch(err){
    const message = err && err.name === 'AbortError' ? 'คำขอถูกยกเลิกหรือหมดเวลา กรุณาลองใหม่อีกครั้ง' : (err.message||'โหลดข้อมูลไม่สำเร็จ');
    showError(message);
    document.getElementById('salesTotal').textContent='-';
    document.getElementById('billCount').textContent='-';
    document.getElementById('avgBill').textContent='-';
    document.getElementById('guestCount').textContent='-';
    document.getElementById('discountTotal').textContent='-';
    document.getElementById('discountBillCount').textContent='โหลดข้อมูลไม่สำเร็จ';
    document.getElementById('recentBillsBody').innerHTML='<tr><td colspan="7" class="empty">โหลดข้อมูลไม่สำเร็จ กรุณาเช็ก query หรือการเชื่อมต่อฐานข้อมูล</td></tr>';
    document.getElementById('topProductsBody').innerHTML='<tr><td colspan="4" class="empty">โหลดข้อมูลไม่สำเร็จ</td></tr>';
    document.getElementById('paymentTableBody').innerHTML='<tr><td colspan="4" class="empty">โหลดข้อมูลไม่สำเร็จ</td></tr>';
    document.getElementById('discountTableBody').innerHTML='<tr><td colspan="4" class="empty">โหลดข้อมูลไม่สำเร็จ</td></tr>';
    document.getElementById('hourlyBars').innerHTML='<div class="empty">โหลดข้อมูลไม่สำเร็จ</div>';
    document.getElementById('saleModeBars').innerHTML='<div class="empty">โหลดข้อมูลไม่สำเร็จ</div>';
  } finally {
    isLoading = false;
    setControlsLoading(false);
    updateFooterNote();
  }
}
reloadBtn.addEventListener('click',()=>loadDashboard(true));
dateInput.addEventListener('change',()=>{ loadDashboard(true); startAutoRefresh(); });
document.addEventListener('visibilitychange',()=>{ if(document.hidden){ stopAutoRefresh(); } else { updateFooterNote(); loadDashboard(); startAutoRefresh(); } });
loadDashboard();
startAutoRefresh();
function sendHeartbeat(){ if(!slotToken) return; fetch('slot.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=heartbeat&token='+encodeURIComponent(slotToken),keepalive:true}).catch(()=>{}); }
function releaseSlot(){ if(!slotToken) return; navigator.sendBeacon('slot.php', new URLSearchParams({action:'release',token:slotToken})); }
setInterval(sendHeartbeat, 30000);
window.addEventListener('beforeunload', releaseSlot);
</script>
</body>
</html>
