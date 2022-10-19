import pytest
from selene.support.conditions import be

from config.credentials import USERNAME, PASSWORD
from config.env import ENV
from core.common.helpers.api_helpers import get_entity
from core.testlib.UI.billrun_cloud.home_page import HomePage
from core.testlib.UI.billrun_cloud.products_page import ProductsPage
from steps.backend_steps.products_steps import Products
from steps.ui_steps.product_steps import ProductsUISteps
import allure


@pytest.mark.ui
@allure.title('Create product by API and then check it is appeared on UI')
@allure.description('PRODUCT-UI-1')
def test_create_product_by_api_and_check_on_ui(driver, login):
    product_key = get_entity(Products().compose_create_payload().create()).get('key')

    driver.get(ENV)
    login(USERNAME, PASSWORD)

    HomePage.product_button.click()
    ProductsUISteps.search_product_by_key(product_key)

    ProductsPage.get_key_button(product_key).should(be.clickable)
