# Sales Dashboard — CLAUDE.md

> เอกสารอธิบายโครงสร้างและการทำงานของโปรเจกต์นี้  
> ไว้ให้ Claude (หรือนักพัฒนา) อ่านก่อนแก้ไขโค้ด

---

## เวอร์ชัน

| รายการ       | ค่า                    |
|-------------|------------------------|
| Version     | 1.4.0                  |
| PHP         | >= 7.4 (รองรับ 8.x)   |
| MySQL       | >= 5.1                 |
| Last update | 2026-05-11             |

### Changelog
- **1.4.0** — ปรับ responsive layout สำหรับมือถือ (2-column stats, bar row layout, ลด padding)
- **1.3.0** — แก้ `$paidWhere` ให้ตรงกับ POS: ตัด `PaidTime IS NOT NULL` และเพิ่ม `ReceiptPayPrice > 0` เพื่อกรองบิล 0 บาทออก
- **1.2.0** — เพิ่ม void/cancelled bills section: แก้ filter บิลที่ถูก void ไม่ให้นับเป็นรายรับ, เพิ่ม query `$sqlVoid`, เพิ่ม `void_summary` และ `void_bills[]` ใน API response, เพิ่ม UI section บน dashboard
- **1.1.0** — ย้าย credentials ออกจาก source code ไปไว้ใน `.env`, เพิ่ม `DASHBOARD_CACHE_TTL`, ลบ `phpinfo.php`, ซ่อน error message ของ DB connection, จำกัด concurrent users สูงสุด 5 คน, เพิ่ม PWA support
- **1.0.0** — เวอร์ชันเริ่มต้น: dashboard หน้าเดียว + API endpoint + caching

---

## โครงสร้างไฟล์

```
Dashboard/
├── dashboard.php          # หน้า HTML หลัก (UI + JavaScript)
├── api_dashboard.php      # API endpoint คืน JSON ข้อมูลยอดขาย
├── dashboard_config.php   # โหลด .env + utility functions
├── slot.php               # จัดการ concurrent user slots (heartbeat/release)
├── manifest.json          # PWA Web App Manifest
├── favicon.png            # ไอคอน 64px
├── icon-192.png           # ไอคอน PWA 192px
├── icon-512.png           # ไอคอน PWA 512px
├── .env                   # credentials และค่า config (ห้าม commit)
├── .env.example           # template สำหรับ .env (commit ได้)
├── .gitignore
├── web.config             # IIS: default document + JSON MIME type
├── cache/                 # JSON cache ของแต่ละวัน
│   ├── dashboard_YYYY-MM-DD.json
│   └── active_slots.json  # ติดตาม concurrent users
└── CLAUDE.md              # ไฟล์นี้
```

---

## การทำงาน

### `dashboard.php`
- ตรวจสอบ concurrent users ก่อนโหลดหน้า (max 5) ด้วย file-based slot system
  - ถ้าเต็มแสดงหน้า 503 "ระบบมีผู้ใช้งานเต็ม X/5"
  - ใช้ cookie `_ds` เก็บ token ต่อ shop directory
- หน้า HTML + CSS + JavaScript ทั้งหมดอยู่ในไฟล์เดียว
- อ่านค่า `$date` จาก `$_GET['date']` (default = วันนี้)
- ส่ง `$DASHBOARD_REFRESH_MS` จาก PHP ลงไปใน JavaScript
- JavaScript โหลดข้อมูลจาก `api_dashboard.php` ด้วย `fetch()`
- Auto-refresh ทุก N วินาที เฉพาะเมื่อดูวันนี้และ tab ยังเปิดอยู่
- รองรับ dark/light theme (บันทึกใน `localStorage`)
- PWA: manifest.json, apple-touch-icon, theme-color meta tags
- Heartbeat ทุก 30 วินาที → `slot.php` เพื่อ keepalive slot
- `navigator.sendBeacon` → `slot.php` เมื่อปิด tab เพื่อ release slot

### `api_dashboard.php`
- รับ `?date=YYYY-MM-DD` และ `?force=1` (บังคับข้าม cache)
- ตรวจ cache ก่อน — ถ้ายังไม่หมดอายุให้คืน JSON จาก cache ทันที
- ถ้า cache หมดหรือ force refresh จะ query DB ทั้งหมด 8 queries:

| Section          | ตาราง                                              |
|------------------|----------------------------------------------------|
| summary          | ordertransaction                                   |
| sale_modes       | ordertransaction + salemode                        |
| hourly           | ordertransaction                                   |
| payment_types    | paydetail + ordertransaction + paytype             |
| top_products     | ordertransaction + orderdetail + orderdiscountdetail + products |
| discount_summary | orderpromotiondiscountdetail + ordertransaction + orderdetail + promotionpricegroup |
| recent_bills     | ordertransaction + salemode + ordertransactionstatus + tablenofororder + tableno |
| void_bills       | ordertransaction + salemode + ordertransactionstatus + tablenofororder + tableno |

- นิยาม "บิลที่ชำระแล้ว" (`$paidWhere`):
  ```
  Deleted = 0
  AND SaleDate = ?
  AND ReceiptPayPrice > 0
  AND TransactionStatusID NOT IN (5, 8, 12, 13, 16)
  AND (TransactionStatusID = 2 OR Description = 'CloseBill')
  ```
- นิยาม "บิลที่ถูก void" (`$voidWhere`):
  ```
  Deleted = 0 AND SaleDate = ? AND TransactionStatusID IN (5, 8, 12, 13, 16)
  ```
- TransactionStatusID ที่ถือว่า void: 5=Void All, 8=Void All NotProduce, 12=Void Other Receipt Header All, 13=Void Other Receipt Header Not Produce, 16=Cancel Bill
- บิล 0 บาท (`ReceiptPayPrice = 0`) ไม่นับเป็นบิลชำระแล้ว เพื่อให้ตรงกับ POS
- Encode JSON ด้วย `JSON_UNESCAPED_UNICODE` พร้อม fallback ถ้า encode ล้มเหลว
- บันทึก cache ด้วย `LOCK_EX` เพื่อป้องกัน race condition

### `dashboard_config.php`
- อ่านไฟล์ `.env` ด้วย parser เรียบง่าย (ไม่ต้องใช้ Composer)
- กำหนดตัวแปร global: `$DB_HOST`, `$DB_PORT`, `$DB_NAME`, `$DB_FALLBACK_IP`, `$DB_CHARSET`, `$DASHBOARD_REFRESH_MS`, `$DASHBOARD_CACHE_TTL`
- `$DB_USER` และ `$DB_PASS` **ล็อคใน code** (`dashboard` / `dashboard@2026`) — ไม่อ่านจาก `.env`
- `db_connect()` — ลอง connect ด้วย resolved IP ก่อน ถ้าล้มเหลวใช้ fallback IP; timeout 3 วินาที; throw RuntimeException เมื่อ connect ไม่ได้
- Utility: `h()`, `money_fmt()`, `bill_status_thai()`, `payment_type_display()`

### `slot.php`
- รับ POST: `action=heartbeat` หรือ `action=release`, `token=<string>`
- เก็บ active slots ใน `cache/active_slots.json` ด้วย LOCK_EX
- TTL = 90 วินาที — slot หมดอายุถ้าไม่มี heartbeat
- ใช้ร่วมกับ `dashboard.php` เพื่อจำกัด concurrent users

---

## การติดตั้ง

```bash
# 1. clone หรือ copy ไฟล์ขึ้น server
# 2. สร้าง .env จาก template
copy .env.example .env   # Windows
cp .env.example .env     # Linux

# 3. แก้ไขค่าใน .env
# DB_HOST, DB_PORT, DB_NAME, DB_FALLBACK_IP

# 4. ตรวจสอบว่า web server มีสิทธิ์ write โฟลเดอร์ cache/
# Windows IIS: ให้ IIS_IUSRS มีสิทธิ์ Write บนโฟลเดอร์ cache/

# 5. วางไฟล์ icon: favicon.png (64px), icon-192.png, icon-512.png
```

---

## ตัวแปรใน `.env`

| ตัวแปร                | ค่า default | คำอธิบาย                                      |
|----------------------|-------------|-----------------------------------------------|
| DB_HOST              | 127.0.0.1   | hostname หรือ IP ของ MySQL                    |
| DB_PORT              | 3307        | port MySQL                                    |
| DB_NAME              | —           | ชื่อ database                                  |
| DB_FALLBACK_IP       | —           | IP สำรอง ถ้า resolve hostname ล้มเหลว          |
| DB_CHARSET           | utf8        | charset ของ connection                        |
| DASHBOARD_REFRESH_MS | 60000       | interval auto-refresh (milliseconds)          |
| DASHBOARD_CACHE_TTL  | 60          | อายุ cache (วินาที), 0 = ปิด cache            |

> `DB_USER` และ `DB_PASS` ไม่ต้องใส่ใน `.env` — ล็อคไว้ใน `dashboard_config.php` แล้ว

---

## MySQL User สำหรับ Dashboard

```sql
-- รันใน context ของ database ที่ต้องการ (MySQL 5.1+)
CREATE USER 'dashboard'@'%' IDENTIFIED BY 'dashboard@2026';
GRANT SELECT ON `ordertransaction` TO 'dashboard'@'%';
GRANT SELECT ON `ordertransactionstatus` TO 'dashboard'@'%';
GRANT SELECT ON `orderdetail` TO 'dashboard'@'%';
GRANT SELECT ON `orderdiscountdetail` TO 'dashboard'@'%';
GRANT SELECT ON `orderpromotiondiscountdetail` TO 'dashboard'@'%';
GRANT SELECT ON `paydetail` TO 'dashboard'@'%';
GRANT SELECT ON `paytype` TO 'dashboard'@'%';
GRANT SELECT ON `products` TO 'dashboard'@'%';
GRANT SELECT ON `promotionpricegroup` TO 'dashboard'@'%';
GRANT SELECT ON `salemode` TO 'dashboard'@'%';
GRANT SELECT ON `tablenofororder` TO 'dashboard'@'%';
GRANT SELECT ON `tableno` TO 'dashboard'@'%';
FLUSH PRIVILEGES;
```

---

## Multi-tenant deployment (IIS)

แต่ละร้านใช้ subdirectory แยกกัน:
```
C:\inetpub\wwwroot\
├── dashboard-shopA\   ← .env ชี้ไป MySQL ร้าน A
├── dashboard-shopB\   ← .env ชี้ไป MySQL ร้าน B
└── dashboard-shopC\   ← .env ชี้ไป MySQL ร้าน C
```
- DB credentials (`dashboard` / `dashboard@2026`) เหมือนกันทุกร้าน
- แต่ละร้านสร้าง user `dashboard` บน MySQL ของตัวเอง
- cookie `_ds` scoped ต่อ path ดังนั้น slot แยกกันต่อ shop

---

## ข้อควรระวัง

- **ห้าม commit `.env`** — มี credentials จริง
- **`cache/` ควรอยู่นอก web root** ถ้า server อนุญาต เพื่อกันดาวน์โหลด JSON ตรง
- ไม่มีระบบ authentication — ควรป้องกันด้วย HTTP Basic Auth หรือ IP whitelist ที่ระดับ web server
- `DB_FALLBACK_IP` ควรเป็นค่าว่างถ้าไม่ใช้
- เมื่อ pull code ใหม่บน VM ให้ลบ `cache/dashboard_*.json` เพื่อ force recalculate
