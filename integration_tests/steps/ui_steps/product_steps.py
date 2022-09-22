import allure

from core.testlib.UI.billrun_cloud.products_page import ProductsPage


class ProductsUISteps:

    @staticmethod
    @allure.step('Search product by key')
    def search_product_by_key(key):
        ProductsPage.search_field.send_keys(key)
        ProductsPage.search_in_field_dropdown.click()
        ProductsPage.tick_search_option(option='key').click()
        ProductsPage.search_button.click()
