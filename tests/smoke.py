"""End-to-end HTTP tests against a disposable copy and database. No dependencies."""
import argparse
import html
import http.cookiejar
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


class Client:
    def __init__(self, base):
        self.base = base
        self.csrf = None
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def get(self, path="index.php"):
        try:
            response = self.opener.open(self.base + path)
            return response.status, response.read().decode(), response.geturl()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode(), error.url

    def post(self, path, action, data=None, csrf=True):
        _, body, _ = self.get(path)
        values = dict(data or {}, action=action)
        if csrf:
            token = re.search(r'name="csrf" value="([a-f0-9]+)"', body)
            if token:
                self.csrf = token.group(1)
            assert self.csrf, f"Missing CSRF field on {path}"
            values["csrf"] = self.csrf
        request = urllib.request.Request(self.base + path, urllib.parse.urlencode(values, doseq=True).encode())
        try:
            response = self.opener.open(request)
            return response.status, response.read().decode(), response.geturl()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode(), error.url


def run():
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", default="php")
    parser.add_argument("--extension-dir", help="Run PHP with -n and this PDO SQLite extension directory")
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with tempfile.TemporaryDirectory(prefix="worksnow-test-") as temporary:
        app = Path(temporary) / "app"
        shutil.copytree(root, app, ignore=shutil.ignore_patterns(".git", "config.local.php", "*.sqlite*", "__pycache__"))
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        cmd = [args.php]
        if args.extension_dir:
            cmd += ["-n", "-d", "extension_dir=" + args.extension_dir, "-d", "extension=pdo_sqlite"]
        cmd += ["-S", f"127.0.0.1:{port}", "router.php"]
        with open(Path(temporary) / "server.log", "w+") as log:
            server = subprocess.Popen(cmd, cwd=app, stdout=log, stderr=log)
            checks = 0

            def check(condition, message):
                nonlocal checks
                assert condition, message
                checks += 1

            try:
                base = f"http://127.0.0.1:{port}/"
                owner, other, customer, tech = (Client(base) for _ in range(4))
                for _ in range(50):
                    try:
                        status, body, _ = owner.get()
                        break
                    except OSError:
                        time.sleep(0.1)
                else:
                    raise RuntimeError("PHP server did not start")
                check(status == 200 and "Welcome back" in body, "Login page")
                check(owner.post("index.php?page=register", "register", {}, csrf=False)[0] == 422, "CSRF rejected")
                account = dict(name="Alex Owner", company="Acme Field Services", slug="acme", email="owner@example.test", password="Test-only-password-123", currency="THB", phone="020000000")
                status, body, _ = owner.post("index.php?page=register", "register", account)
                check(status == 200 and "Your workspace is ready" in body, "Registration")
                check("No tickets yet" in body and "THB 0.00" in body, "Honest empty dashboard")
                status, body, _ = owner.post("index.php?page=services", "service_save", dict(name="AC repair", description="Cooling diagnostics", price="500.25"))
                check(status == 200 and "AC repair" in body, "Create service")
                owner.post("index.php?page=team", "team_create", dict(name="Taylor Tech", email="tech@example.test", password="Technician-password-123"))
                status, body, _ = customer.get("index.php?page=request&business=acme")
                check(status == 200 and "AC repair" in body, "Public catalog")
                status, body, tracking_url = customer.post("index.php?page=request&business=acme", "request", dict(business="acme", service_id="1", name="Jamie Customer", email="customer@example.test", phone="020000001", address="100 Service Lane", title="Air conditioner not cooling", description="Fan runs but air is warm. <script>alert(1)</script>"))
                check(status == 200 and "Request received" in body and "token=" in tracking_url, "Public request and tracking")
                check("&lt;script&gt;alert(1)&lt;/script&gt;" in body and "<script>alert(1)</script>" not in body, "Stored HTML escaped")
                token = urllib.parse.parse_qs(urllib.parse.urlparse(tracking_url).query)["token"][0]
                track = "index.php?page=track&token=" + token
                status, body, _ = owner.get("index.php?page=ticket&id=1")
                check(status == 200 and "Jamie Customer" in body, "Owner ticket access")
                status, body, _ = owner.post("index.php?page=ticket&id=1", "ticket_update", dict(id="1", status="scheduled", assigned_to="2", priority="high", scheduled_at="2026-12-01T09:30"))
                check(status == 200 and "Ticket updated" in body, "Scheduling and assignment")
                status, body, _ = owner.post("index.php?page=ticket&id=1", "ticket_update", dict(id="1", status="completed", assigned_to="2", priority="high", scheduled_at="2026-12-01T09:30"))
                check(status == 422 and "Start work" in body, "Ticket transition validation")
                status, body, _ = owner.post("index.php?page=ticket&id=1", "quote_create", {"ticket_id":"1", "item_name[]":["Diagnostic visit", "Replacement part"], "item_qty[]":["1","2"], "item_price[]":["500.25","100.10"], "tax_rate":"7", "due_date":"2099-12-31", "notes":"Includes labor and materials."})
                check(status == 200 and "THB 749.48" in body, "Quote integer calculations and tax rounding")
                check("QT-0001" not in customer.get(track)[1], "Draft quote hidden")
                check(customer.get("index.php?page=document&id=1&token=" + token)[0] == 404, "Draft document access denied")
                status, body, _ = owner.post("index.php?page=ticket&id=1", "document_send", dict(id="1"))
                check(status == 200 and "Document is now visible" in body, "Issue quote")
                status, body, _ = customer.get(track)
                check("QT-0001" in body and "Accept quote" in body, "Issued quote visible")
                status, body, _ = customer.post(track, "quote_response", dict(document_id="1", token=token, response="accepted"))
                check(status == 200 and "Quote accepted" in body, "Customer accepts quote")
                check(customer.post(track, "quote_response", dict(document_id="1", token=token, response="declined"))[0] == 422, "Cannot alter accepted quote")
                status, body, _ = owner.post("index.php?page=ticket&id=1", "invoice_create", dict(id="1", due_date="2099-12-31", notes="Pay by bank transfer."))
                check(status == 200 and "INV-0002" in body, "Invoice from accepted quote")
                check(owner.post("index.php?page=ticket&id=1", "invoice_create", dict(id="1", due_date="2099-12-31"))[0] == 422, "Duplicate invoice blocked")
                check(owner.post("index.php?page=ticket&id=1", "payment", dict(id="2", amount="100.00", method="cash"))[0] == 422, "Cannot pay draft")
                owner.post("index.php?page=ticket&id=1", "document_send", dict(id="2"))
                check(owner.post("index.php?page=ticket&id=1", "payment", dict(id="2", amount="800.00", method="cash"))[0] == 422, "Overpayment rejected")
                status, body, _ = owner.post("index.php?page=ticket&id=1", "payment", dict(id="2", amount="200.00", method="bank_transfer", reference="BANK-001"))
                check(status == 200 and "THB 549.48" in body and "Partial" in body, "Partial payment")
                status, body, _ = owner.post("index.php?page=ticket&id=1", "payment", dict(id="2", amount="549.48", method="cash", reference="CASH-002"))
                check(status == 200 and "Paid" in body and "Balance THB 0.00" in body, "Full settlement")
                status, body, _ = customer.get("index.php?page=document&id=2&token=" + token)
                check(status == 200 and "BANK-001" in body and "CASH-002" in body, "Printable invoice with payment history")
                tech.post("index.php?page=login", "login", dict(email="tech@example.test", password="Technician-password-123"))
                check(tech.get("index.php?page=ticket&id=1")[0] == 200, "Technician assigned ticket access")
                check(tech.get("index.php?page=invoices")[0] == 404, "Technician billing access denied")
                check(tech.post("index.php?page=ticket&id=1", "payment", dict(id="2", amount="1.00", method="cash"))[0] == 422, "Technician billing mutation denied")
                status, body, _ = tech.post("index.php?page=ticket&id=1", "ticket_update", dict(id="1", status="in_progress"))
                check(status == 200 and "In Progress" in body, "Technician starts work")
                tech.post("index.php?page=ticket&id=1", "note", dict(id="1", message="Repair complete. System tested."))
                status, body, _ = tech.post("index.php?page=ticket&id=1", "ticket_update", dict(id="1", status="completed"))
                check(status == 200 and "Completed" in body, "Technician completes work")
                check("Repair complete. System tested." in customer.get(track)[1], "Public progress updates")
                account.update(name="Other Owner", company="Other Business", slug="other", email="other@example.test")
                other.post("index.php?page=register", "register", account)
                check(other.get("index.php?page=ticket&id=1")[0] == 404, "Cross-business ticket read denied")
                check(other.get("index.php?page=document&id=2")[0] == 404, "Cross-business document read denied")
                check("Jamie Customer" not in other.get("index.php?page=customers")[1], "Customer isolation")
                check(other.post("index.php?page=services", "service_save", dict(id="1", name="Tampered", description="", price="1.00", active="1"))[0] == 422, "Cross-business service mutation denied")
                check(customer.get("index.php?page=track&token=" + "a"*64)[0] == 404, "Unknown tracking token rejected")
                for path in ["storage/worksnow.sqlite", "app/bootstrap.php", "config.example.php", "tests/smoke.py", "assets/../config.example.php"]:
                    check(customer.get(path)[0] == 404, "Private path denied: " + path)
                for page in ["dashboard", "tickets", "schedule", "customers", "services", "quotes", "invoices", "team", "settings", "new-ticket"]:
                    status, body, _ = owner.get("index.php?page=" + page)
                    check(status == 200 and "PHP Warning" not in body and "Fatal error" not in body, "Render " + page)
                check(owner.post("index.php?page=settings", "password", dict(current_password="wrong-password", password="New-test-password-123"))[0] == 422, "Password requires current password")
                owner.post("index.php?page=settings", "password", dict(current_password="Test-only-password-123", password="New-test-password-123"))
                owner.post("index.php", "logout")
                check("Welcome back" in owner.get()[1], "Logout")
                check("Let’s get good work done" in owner.post("index.php?page=login", "login", dict(email="owner@example.test", password="New-test-password-123"))[1], "Login with changed password")
                connection = sqlite3.connect(app / "storage" / "worksnow.sqlite")
                check(connection.execute("SELECT total,paid,status FROM documents WHERE id=2").fetchone() == (74948,74948,"paid"), "Persisted accounting integrity")
                check(connection.execute("SELECT COUNT(*) FROM payments").fetchone()[0] == 2, "Payment ledger integrity")
                connection.close()
                log.flush(); log.seek(0)
                output = log.read()
                check("Fatal error" not in output and "PHP Warning" not in output, "No runtime warnings")
                print(f"PASS: {checks} checks across request > ticket > quote > approval > invoice > payment, permissions, security, and all workspace pages.")
            except Exception:
                log.flush(); log.seek(0)
                print(log.read()[-6000:])
                raise
            finally:
                server.terminate()
                server.wait(timeout=10)


if __name__ == "__main__":
    run()
