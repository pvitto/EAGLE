from playwright.sync_api import sync_playwright, expect

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context()
    page = context.new_page()

    try:
        # Go to the login page first
        page.goto("http://localhost:8000/login.php")

        # Fill in the login form and submit
        page.get_by_label("Email").fill("test@example.com") # Replace with a valid test user
        page.get_by_label("Contraseña").fill("password") # Replace with the user's password
        page.get_by_role("button", name="Iniciar Sesión").click()

        # Wait for navigation to the main page
        expect(page).to_have_url("http://localhost:8000/index.php")
        expect(page.locator("#tab-general")).to_be_visible(timeout=10000)

        # Click the "Gestionar Sedes" tab
        page.get_by_role("button", name="Gestionar Sedes").click()

        # Wait for the client selection dropdown to be visible
        client_select = page.locator("#client-select-sites")
        expect(client_select).to_be_visible()

        # Select a client (assuming the first option is a valid client)
        # We need to find a client that exists. Let's assume 'Olimpica Tangano' is one.
        client_select.select_option(label="Olimpica Tangano")

        # Wait for the sites list to be populated.
        # We check for a specific element that indicates loading is complete.
        # Looking for a site name or the "No hay sedes" message.
        expect(page.locator("#sites-list-sites-page").locator("p, div")).to_be_visible()

        # Take a screenshot of the "Gestionar Sedes" panel
        page.locator("#content-manage-sites").screenshot(path="jules-scratch/verification/sites_functionality_verification.png")

        print("Verification successful: 'Gestionar Sedes' panel functionality verified.")

    except Exception as e:
        print(f"An error occurred during verification: {e}")
        page.screenshot(path="jules-scratch/verification/verification_error.png")

    finally:
        browser.close()

with sync_playwright() as playwright:
    run(playwright)
