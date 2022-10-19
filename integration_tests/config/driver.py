from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager

from config.env import PROJECT_DIRECTORY


class Driver:
    def __init__(self, **kwargs):
        self.kwargs = kwargs
        # specify Chrome version due to webdriver-manager was not updated with new mac m1 path
        # https://bugs.chromium.org/p/chromedriver/issues/detail?id=4215
        self.kwargs['service'] = Service(
            ChromeDriverManager(version='106.0.5249.21', path=PROJECT_DIRECTORY).install())
        print(f"{self.kwargs['service'].path=}")
        options = webdriver.ChromeOptions()
        options.add_argument("--headless")
        if '--headless' in options.arguments:
            options.add_argument("--window-size=1920,1080")
            options.add_argument("--no-sandbox")
        self.kwargs['options'] = options

    def start(self):
        driver = webdriver.Chrome(**self.kwargs)
        return driver
