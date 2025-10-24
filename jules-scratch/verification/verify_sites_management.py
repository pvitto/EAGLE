from playwright.sync_api import sync_playwright, expect

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context()
    page = context.new_page()

    # Navegar al archivo HTML estático
    page.goto("file:///app/jules-scratch/verification/test_manage_clients.html")

    # Hacer clic en el primer cliente de la lista
    page.locator("tbody#clients-table-body-ajax tr.client-row").first.click()

    # Esperar a que el panel de gestión de sedes sea visible
    sites_panel = page.locator("#sites-management-panel")
    expect(sites_panel).to_be_visible()

    # Tomar captura de pantalla del panel de sedes
    page.screenshot(path="jules-scratch/verification/manage_sites_panel.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
