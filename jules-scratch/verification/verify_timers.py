
import asyncio
from playwright.async_api import async_playwright

async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        page = await browser.new_page()
        await page.goto("http://localhost:8000")

        # Esperar un tiempo fijo para que la página se cargue completamente
        await page.wait_for_timeout(5000)

        # Tomar una captura de pantalla para diagnóstico
        await page.screenshot(path="jules-scratch/verification/diagnostic_screenshot.png")
        await browser.close()

if __name__ == "__main__":
    asyncio.run(main())
