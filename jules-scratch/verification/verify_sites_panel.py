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

        # Wait for navigation to the main page and for a known element to be visible
        expect(page.locator("#tab-general")).to_be_visible(timeout=10000)

        # Click the "Gestionar Sedes" tab
        sites_tab = page.get_by_role("button", name="Gestionar Sedes")
        sites_tab.click()

        # Wait for the content of the "Gestionar Sedes" panel to be loaded
        # We can check for a specific element that should be inside manage_sites.php
        expect(page.locator("#content-manage-sites h1")).to_have_text("Gestionar Sedes")

        # Take a screenshot of the entire page to verify the panel is visible and has content
        page.screenshot(path="jules-scratch/verification/sites_panel_verification.png")

        print("Verification successful: 'Gestionar Sedes' panel loaded correctly.")

    except Exception as e:
        print(f"An error occurred during verification: {e}")
        page.screenshot(path="jules-scratch/verification/verification_error.png")

    finally:
        browser.close()

with sync_playwright() as playwright:
    run(playwright)
