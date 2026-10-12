"""Headless admin render audit. Install Playwright outside the runtime tree.

Requires GSO_UI_BASE_URL (loopback), GSO_UI_CREDENTIALS (private JSON with
isolated role fixtures), and GSO_UI_EVIDENCE_DIR (outside the web root).
Only login, GET navigation, and reversible browser controls are exercised.
"""
import json
import os
from pathlib import Path
import re
from urllib.parse import urljoin, urlparse

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ["GSO_UI_BASE_URL"].rstrip("/") + "/"
EVIDENCE = Path(os.environ["GSO_UI_EVIDENCE_DIR"]).resolve()
FIXTURE = json.loads(Path(os.environ["GSO_UI_CREDENTIALS"]).read_text())
if (urlparse(BASE).hostname not in {"127.0.0.1", "localhost", "::1"}
        or EVIDENCE.is_relative_to(ROOT)
        or not re.fullmatch(r"gso_r\d+[ab]?_test_\w+", FIXTURE["database"])):
    raise RuntimeError("Loopback, isolated fixtures and private evidence required")
EVIDENCE.mkdir(parents=True, exist_ok=True)

ROUTES = {
    "dashboard": "gso.admin_workspace", "requests": "request.view_queue",
    "create_walkin": "walk_in.create", "reschedules": "request.reschedule.manage",
    "on_loan": "release.manage", "incidents": "gso.admin_workspace",
    "inventory": "inventory.view", "equipment_units": "inventory.manage_units",
    "maintenance": "work_order.manage", "preventive_plans": "work_order.manage",
    "maintenance_history": "work_order.manage", "ai_knowledge": "gso.admin_workspace",
    "reports": "gso.admin_workspace", "activity_logs": "gso.admin_workspace",
    "notifications": "notifications.view_own", "accounts_dashboard": "accounts.manage",
    "borrowers_accounts": "accounts.manage", "admin_accounts": "accounts.manage",
}
KEY_PAGES = {"create_walkin", "reschedules", "on_loan", "ai_knowledge",
             "maintenance", "preventive_plans", "maintenance_history"}
results, failures, checks = [], [], 0


def check(ok, message):
    global checks
    checks += 1
    if not ok:
        failures.append(message)


def login(context, credentials):
    page = context.new_page()
    page.goto(urljoin(BASE, "auth/login.php"))
    page.locator('[name="login_input"]').fill(credentials["username"])
    page.locator('[name="password"]').fill(credentials["password"])
    page.locator('button[type="submit"]').click()
    page.wait_for_load_state("load")
    check("/auth/login.php" not in page.url, "Role login: " + credentials["role"])
    return page


with sync_playwright() as pw:
    browser = pw.chromium.launch(
        executable_path=os.environ.get("GSO_UI_CHROME", r"C:\Program Files\Google\Chrome\Application\chrome.exe"),
        headless=True,
    )
    for role_key, credentials in FIXTURE["roles"].items():
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = login(context, credentials)
        role_sidebar = page.locator('.sidebar a').evaluate_all(
            "els=>els.map(e=>({label:e.textContent.trim(),href:e.getAttribute('href')}))")
        (EVIDENCE / f'sidebar_{role_key}.json').write_text(json.dumps(role_sidebar, indent=2))
        for name, permission in ROUTES.items():
            response = page.goto(urljoin(BASE, "admin/" + name + ".php"))
            allowed = permission in credentials["permissions"]
            at_route = urlparse(page.url).path.endswith("/admin/" + name + ".php")
            check(at_route == allowed, f"Access {role_key}/{name}: expected {allowed}")
            if allowed:
                check(response.status == 200, f"HTTP {role_key}/{name}")
                check(page.locator("footer.footer, #home, .hero-video").count() == 0,
                      f"Public markup {role_key}/{name}")
            check((page.locator("#gsoChatLauncher").count() > 0) == ("chatbot.use" in credentials["permissions"]),
                  f"Chat authorization {role_key}/{name}")
            results.append({"kind": "role", "role": role_key, "page": name,
                            "allowed": allowed, "status": response.status, "final_url": page.url})
        context.close()

    context = browser.new_context(viewport={"width": 1440, "height": 900})
    page = login(context, FIXTURE["roles"]["head"])
    sidebar = page.locator(".sidebar .menu a").evaluate_all(
        "els => els.map(e => ({label:e.textContent.trim(), href:e.getAttribute('href')}))")
    inventory = []
    for item in sidebar:
        if "logout.php" in item["href"]:
            inventory.append({**item, "purpose": "Intentional sign-out endpoint"})
            continue
        page.goto(urljoin(page.url, item["href"]))
        inventory.append({**item, **page.evaluate("""() => ({url:location.href,
            stylesheets:[...document.querySelectorAll('link[rel=stylesheet]')].map(e=>e.getAttribute('href')),
            scripts:[...document.scripts].filter(e=>e.src).map(e=>e.getAttribute('src')),
            heading:document.querySelector('.page-content h1,.page-content h2')?.textContent.trim(),
            publicFooter:!!document.querySelector('footer.footer')})""")})
    (EVIDENCE / "sidebar_inventory.json").write_text(json.dumps(inventory, indent=2))

    pages = [name for name, permission in ROUTES.items()
             if permission in FIXTURE["roles"]["head"]["permissions"]]
    for width, height in [(1440, 900), (1366, 768), (1920, 1080)]:
        page.set_viewport_size({"width": width, "height": height})
        for name in pages:
            errors, asset_failures = [], []
            def on_error(error):
                errors.append(str(error))
            def on_response(response):
                if response.status >= 400:
                    asset_failures.append({"url": response.url, "status": response.status})
            page.on("pageerror", on_error)
            page.on("response", on_response)
            response = page.goto(urljoin(BASE, "admin/" + name + ".php"))
            page.wait_for_load_state("networkidle")
            body = page.locator("body").inner_text()
            check(response.status == 200, f"Render HTTP {name}/{width}")
            check(not re.search(r"PHP (Warning|Fatal)|Fatal error:|Uncaught .*Exception|temporarily unavailable", body),
                  f"PHP error markers {name}/{width}")
            check(not errors, f"JavaScript {name}/{width}: {errors}")
            check(not [a for a in asset_failures if urlparse(a["url"]).hostname == urlparse(BASE).hostname],
                  f"Local assets {name}/{width}: {asset_failures}")
            metrics = page.evaluate("""() => {
                const rect=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}};
                const main=document.querySelector('.main-content'), heading=document.querySelector('.page-content h1,.page-content h2');
                return {documentWidth:document.documentElement.scrollWidth,viewport:innerWidth,
                  heading:heading&&rect(heading),topbar:rect(document.querySelector('.topbar')),
                  sidebar:rect(document.querySelector('.sidebar')),main:rect(main),
                  cards:[...document.querySelectorAll('.page-content > .card')].map(rect),
                  publicFooter:!!document.querySelector('footer.footer'),
                  oversizedFilters:[...document.querySelectorAll('.table-toolbar input,.table-toolbar select')]
                    .filter(e=>e.getClientRects().length && e.getBoundingClientRect().height>64).map(e=>e.name),
                  rawControls:[...document.querySelectorAll('.page-content input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]),.page-content select,.page-content textarea')]
                    .filter(e=>e.getClientRects().length && getComputedStyle(e).borderRadius==='0px').map(e=>e.name)};
            }""")
            check(metrics["documentWidth"] <= width + 1, f"Horizontal overflow {name}/{width}")
            check(metrics["main"]["x"] >= metrics["sidebar"]["right"] - 1, f"Sidebar overlap {name}/{width}")
            check(metrics["heading"] and metrics["heading"]["y"] >= metrics["topbar"]["bottom"] - 1,
                  f"Topbar overlap {name}/{width}")
            check(all(c["right"] <= width + 1 and c["x"] >= metrics["main"]["x"] for c in metrics["cards"]),
                  f"Card clipping {name}/{width}")
            check(not metrics["publicFooter"], f"Footer leakage {name}/{width}")
            check(not metrics["rawControls"], f"Raw controls {name}/{width}: {metrics['rawControls']}")
            check(not metrics["oversizedFilters"], f"Stretched filters {name}/{width}")
            check(page.locator('.global-back-btn').evaluate("e=>getComputedStyle(e).position") == "static",
                  f"Back action in page layout {name}/{width}")
            for fraction in [0, 0.5, 1]:
                page.evaluate('f=>window.scrollTo(0,f*document.documentElement.scrollHeight)', fraction)
                overlaps = page.evaluate("""() => {
                    const chat=document.querySelector('#gsoChatLauncher').getBoundingClientRect();
                    return [...document.querySelectorAll('.page-content input:not([type=hidden]),.page-content select,.page-content textarea,.page-content button')]
                      .filter(e=>e.getClientRects().length).filter(e=>{
                        const box=e.getBoundingClientRect();
                        let left=box.left,right=box.right,top=box.top,bottom=box.bottom;
                        // Scrollable tables paint only inside their scrollport.
                        for(let parent=e.parentElement;parent;parent=parent.parentElement){
                          const style=getComputedStyle(parent),clip=parent.getBoundingClientRect();
                          if(style.overflowX!=='visible'){left=Math.max(left,clip.left);right=Math.min(right,clip.right);}
                          if(style.overflowY!=='visible'){top=Math.max(top,clip.top);bottom=Math.min(bottom,clip.bottom);}
                        }
                        return left<right && top<bottom && left<chat.right && right>chat.left && top<chat.bottom && bottom>chat.top;
                      }).map(e=>e.name||e.textContent.trim());
                }""")
                check(not overlaps, f"Chat overlaps controls {name}/{width}/{fraction}: {overlaps}")
            page.evaluate('window.scrollTo(0,0)')
            if name == "create_walkin":
                check(page.locator('[name="walkin_full_name"]').count() == 1, "Walk-in form marker")
            if name in KEY_PAGES:
                page.screenshot(path=str(EVIDENCE / f"{name}_{width}.png"), full_page=True)
            page.evaluate("window.scrollTo(0,document.documentElement.scrollHeight)")
            if name == "create_walkin":
                check(page.locator('[name="notes"]').is_visible(), "Walk-in bottom fields")
                page.screenshot(path=str(EVIDENCE / f"{name}_{width}_bottom.png"))
            results.append({"kind": "render", "page": name, "width": width, "height": height,
                            "metrics": metrics, "errors": errors, "asset_failures": asset_failures})
            page.remove_listener("pageerror", on_error)
            page.remove_listener("response", on_response)

    page.goto(urljoin(BASE, "admin/create_walkin.php"))
    page.locator("#add-walkin-line").click()
    check(page.locator('[name="lines[1][resource_id]"]').count() == 1, "Walk-in resource-row cloning")
    page.locator(".walkin-line").last.locator("button").click()
    check(page.locator(".walkin-line").count() == 1, "Walk-in resource-row removal")
    page.locator("#gsoChatLauncher").click()
    check(page.locator("#gsoChatPanel").is_visible(), "Chat launcher opens panel")

    page.goto(urljoin(BASE, "admin/ai_knowledge.php"))
    page.locator('.request-table .row-actions-trigger').first.click()
    menu = page.locator('.request-table .row-actions.show .row-actions-menu')
    check(menu.is_visible() and all(text in menu.inner_text() for text in ['Edit', 'Deactivate', 'Delete']),
          "Knowledge row actions use the existing dropdown")
    page.screenshot(path=str(EVIDENCE / 'ai_knowledge_actions.png'))
    edit_href = menu.locator('a[href*="?edit="]').get_attribute('href')
    page.goto(urljoin(page.url, edit_href))
    check(page.locator('.settings-form-grid [name="entry_id"]').input_value() != '0', "Knowledge edit form preserves entry ID")
    check(page.locator('[name="title"]').input_value() != '', "Knowledge edit values render")
    page.screenshot(path=str(EVIDENCE / 'ai_knowledge_edit.png'), full_page=True)

    page.goto(urljoin(BASE, 'admin/equipment_units.php'))
    manage_href = page.locator('a[href*="equipment_units.php?resource_id="]').first.get_attribute('href')
    page.goto(urljoin(page.url, manage_href))
    check(page.locator('[name="asset_tag"]').count() == 1, "Equipment unit registration form")
    check(page.locator('[name="asset_tag"]').evaluate("e=>!!e.closest('.form-row')"), "Equipment registration uses form rows")
    page.screenshot(path=str(EVIDENCE / 'equipment_units_detail.png'), full_page=True)

    page.set_viewport_size({'width': 1440, 'height': 900})
    for name in ['maintenance', 'preventive_plans']:
        page.goto(urljoin(BASE, 'admin/' + name + '.php'))
        page.locator('.request-table .row-actions-trigger').first.click()
        menu = page.locator('.request-table .row-actions.show .row-actions-menu')
        check(menu.is_visible(), f'Populated {name} action menu')
        controls = menu.locator('input:not([type=hidden]),select').evaluate_all(
            "els=>els.map(e=>({name:e.name,radius:getComputedStyle(e).borderRadius,height:e.getBoundingClientRect().height}))")
        check(controls and all(e['radius'] != '0px' and e['height'] >= 42 for e in controls),
              f'Populated {name} controls use admin styling: {controls}')
        check(menu.evaluate("e=>e.getBoundingClientRect().bottom <= e.closest('.table-wrapper').getBoundingClientRect().bottom"),
              f'Populated {name} menu stays within table scrollport')
        check(menu.locator('button[type=submit]').evaluate_all("""els=>els.every(e=>{
            const rgb=s=>s.match(/[0-9.]+/g).slice(0,3).map(Number);
            const luminance=c=>c.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;})
                .reduce((sum,v,i)=>sum+v*[.2126,.7152,.0722][i],0);
            let ancestor=e,background;
            do{background=getComputedStyle(ancestor).backgroundColor;ancestor=ancestor.parentElement;}
            while(ancestor && background==='rgba(0, 0, 0, 0)');
            const foreground=luminance(rgb(getComputedStyle(e).color)),surface=luminance(rgb(background));
            return (Math.max(foreground,surface)+.05)/(Math.min(foreground,surface)+.05)>=4.5;
        })"""), f'Populated {name} action labels have readable contrast')
        menu.locator('button[type=submit]').last.scroll_into_view_if_needed()
        page.screenshot(path=str(EVIDENCE / f'{name}_actions.png'))
        page.keyboard.press('Escape')
        check(page.locator('.row-actions-clearance').count() == 0, f'{name} menu clearance resets on close')

    for width in [768, 390]:
        page.set_viewport_size({'width': width, 'height': 844})
        for name in ['create_walkin', 'reschedules', 'ai_knowledge', 'preventive_plans']:
            page.goto(urljoin(BASE, 'admin/' + name + '.php'))
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'),
                  f"Responsive overflow {name}/{width}")
            check(page.locator('footer.footer').count() == 0, f"Responsive public footer {name}/{width}")
            page.screenshot(path=str(EVIDENCE / f'{name}_{width}.png'), full_page=True)
    context.close()

    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    page = login(context, FIXTURE['roles']['staff'])
    page.goto(urljoin(BASE, 'admin/create_walkin.php'))
    check(page.locator('#gsoChatLauncher').evaluate('e=>e.getBoundingClientRect().width') == 44,
          'Staff compact chat launcher')
    check(page.locator('.workspace-back-btn').is_visible(), 'Staff workspace Back action')
    page.screenshot(path=str(EVIDENCE / 'create_walkin_staff.png'), full_page=True)
    context.close()

    guest = browser.new_context()
    page = guest.new_page()
    response = page.goto(urljoin(BASE, "pages/landing.php"))
    check(response.status == 200 and page.locator("footer.footer").count() == 1, "Public landing retains footer")
    guest.close()
    browser.close()

report = {"checks": checks, "failures": failures, "results": results}
(EVIDENCE / "render_results.json").write_text(json.dumps(report, indent=2))
print(json.dumps({"checks": checks, "failures": failures, "screenshots": len(list(EVIDENCE.glob('*.png')))}))
raise SystemExit(bool(failures))
