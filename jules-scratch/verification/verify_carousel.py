import re
import os
from playwright.sync_api import Page, expect, sync_playwright

def run_test():
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        try:
            # Get the absolute path to the test HTML file
            file_path = os.path.abspath('jules-scratch/verification/carousel_test.html')
            test_carousel_functionality(page, f'file://{file_path}')
        except Exception as e:
            print(f"An error occurred during Playwright execution: {e}")
            page.screenshot(path="jules-scratch/verification/error_screenshot.png")
        finally:
            browser.close()

def test_carousel_functionality(page: Page, url: str):
    print(f"Navigating to static page: {url}")
    page.goto(url)

    print("Verifying initial state of the carousel...")
    # Expect the first item to be visible
    first_item = page.locator('.digitador-carousel-item').nth(0)
    expect(first_item).to_be_visible()

    # Expect the counter to be "1/3"
    counter = page.locator('#digitador-carousel-counter')
    expect(counter).to_have_text('1/3')

    # Take a screenshot of the initial state
    page.screenshot(path="jules-scratch/verification/verification_initial.png")

    print("Clicking 'next' button...")
    next_button = page.locator('#digitador-carousel-next')
    next_button.click()

    print("Verifying second state of the carousel...")
    # Expect the second item to be visible
    second_item = page.locator('.digitador-carousel-item').nth(1)
    expect(second_item).to_be_visible()

    # Expect the counter to be "2/3"
    expect(counter).to_have_text('2/3')

    # Take the final screenshot
    page.screenshot(path="jules-scratch/verification/verification.png")
    print("Final screenshot taken successfully.")

if __name__ == "__main__":
    run_test()
