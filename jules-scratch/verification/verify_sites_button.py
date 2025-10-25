from playwright.sync_api import sync_playwright, expect

def run(playwright):
    browser = playwright.chromium.launch(headless=True)
    context = browser.new_context()
    page = context.new_page()

    try:
        # Go to the index page, assuming it's served at the root
        page.goto("http://localhost:8000/index.php?content_only=true") # Use content_only to avoid login issues if possible

        # Wait for the main content to be loaded, check for a known element
        expect(page.locator("#tab-general")).to_be_visible(timeout=10000)

        # Specifically check for the new "Gestionar Sedes" button
        sites_button = page.get_by_role("button", name="Gestionar Sedes")

        # Assert that the button is visible
        expect(sites_button).to_be_visible()

        # Take a screenshot of the navigation area
        page.locator(".main-nav").screenshot(path="jules-scratch/verification/sites_button_verification.png")

        print("Verification successful: 'Gestionar Sedes' button is visible.")

    except Exception as e:
        print(f"An error occurred during verification: {e}")
        page.screenshot(path="jules-scratch/verification/verification_error.png")

    finally:
        browser.close()

with sync_playwright() as playwright:
    run(playwright)
