from playwright.sync_api import sync_playwright
import os

def run(playwright):
    browser = playwright.chromium.launch()
    page = browser.new_page()

    # Construir la ruta al archivo local
    file_path = "file://" + os.path.abspath("jules-scratch/verification/reminder_form.html")
    page.goto(file_path)

    # Verificar que el formulario es visible
    form = page.locator("#reminder-form")
    form.wait_for(state="visible")

    # Verificar que el select es visible
    user_select = page.locator("#reminder-user")
    user_select.wait_for(state="visible")

    page.screenshot(path="jules-scratch/verification/verification.png")
    browser.close()

with sync_playwright() as playwright:
    run(playwright)
