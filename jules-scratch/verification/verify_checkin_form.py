from playwright.sync_api import sync_playwright, expect

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context()
    page = context.new_page()

    # Navegar al archivo HTML estático
    page.goto("file:///app/jules-scratch/verification/test_checkin.html")

    # Seleccionar el cliente que tiene sedes
    page.select_option("#client_id", "1")

    # Esperar a que el selector de sedes esté habilitado
    site_select = page.locator("#client_site_id")
    expect(site_select).to_be_enabled()

    # Tomar captura de pantalla del formulario
    page.screenshot(path="jules-scratch/verification/checkin_form_with_sites.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
