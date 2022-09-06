import pytest

from conftest import add_skip_mark
from steps.backend_steps.customers_steps import Customers, CustomerAssertionSteps


@pytest.mark.parametrize('optional_params', [
    {'invoice_detailed': False, 'invoice_shipping_method': 'email'},
    add_skip_mark(case={'invoice_detailed': True, 'invoice_shipping_method': None},
                  reason='https://billrun.atlassian.net/browse/BRCD-3826')
])
@pytest.mark.smoke
def test_create_customer(optional_params):
    customer = Customers()

    customer.compose_create_payload(**optional_params).create()
    CustomerAssertionSteps(customer).validate_post_response_is_correct()

    customer.get_by_id()
    CustomerAssertionSteps(customer).validate_get_response_is_correct()


@pytest.mark.smoke
def test_delete_customer():
    customer = Customers()

    customer.compose_create_payload().create()
    CustomerAssertionSteps(customer).validate_post_response_is_correct()

    customer.delete()
    CustomerAssertionSteps(customer).check_object_is_deleted_successfully()
