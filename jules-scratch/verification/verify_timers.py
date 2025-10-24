from playwright.sync_api import sync_playwright

def run(playwright):
    browser = playwright.chromium.launch()
    page = browser.new_page()
    # Assuming the app is served at the root of a web server on port 8080 (or your configured port)
    # Adjust the URL if your local server setup is different.
    page.goto("http://localhost:8080/index.php")

    # Wait for the main content area to be visible to ensure the page has loaded
    page.wait_for_selector("#content-operaciones")

    # Give timers a moment to initialize and render
    page.wait_for_timeout(2000)

    # Take a screenshot of the main content area
    page.locator("#content-operaciones").screenshot(path="jules-scratch/verification/verification.png")

    browser.close()

with sync_playwright() as playwright:
    run(playwright)
