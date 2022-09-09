import pytest
from pytest_testrail.plugin import pytestrail

from core.common.entities import RevisionStatus
from core.common.utils import get_random_str, get_id_from_response
from steps.backend_steps.products_steps import Products, ProductAssertionSteps


@pytestrail.case('C2684')
@pytest.mark.smoke
def test_create_product():
    product = Products()

    product.compose_create_payload().create()
    ProductAssertionSteps(product).check_post_response_is_successfully()

    product.get_by_id()
    ProductAssertionSteps(product).validate_get_response_is_correct()


@pytestrail.case('C2685')
@pytest.mark.smoke
def test_update_product():
    product = Products()

    product.compose_create_payload().create()
    ProductAssertionSteps(product).check_post_response_is_successfully()

    params_to_update = {
        'invoice_label': get_random_str(), 'description':get_random_str(), 'pricing_method': 'volume'
    }
    product.compose_update_payload(**params_to_update).update()
    ProductAssertionSteps(product).check_update_response_is_successfully()

    product.get_by_id()
    ProductAssertionSteps(product).validate_get_response_is_correct(
        expected_response=product.generate_expected_response_after_updating())


@pytestrail.case('C2686')
@pytest.mark.smoke
@pytest.mark.parametrize('to', [
    True,  # set random future date
    False,  # w/o to param
    "past_date"  # set random past date
])
def test_close_product(to):
    product = Products()

    product.compose_create_payload().create()
    ProductAssertionSteps(product).check_post_response_is_successfully()

    product.compose_close_payload(
        to=to, date_in_past=False if to != 'past_date' else True).close()
    ProductAssertionSteps(product).check_close_response_is_successful()

    ProductAssertionSteps(product).check_object_revision_status(
        status=RevisionStatus.EXPIRED if to in [False, "past_date"] else RevisionStatus.ACTIVE)

    ProductAssertionSteps(product).validate_get_response_is_correct(
        expected_response=product.generate_expected_response_after_close())


@pytestrail.case('C2687')
@pytest.mark.smoke
def test_close_and_new_product():
    product = Products()

    get_id_from_response(product.compose_create_payload().create())  # init revision
    ProductAssertionSteps(product).check_post_response_is_successfully()

    product.get_by_id()
    ProductAssertionSteps(product).validate_get_response_is_correct()

    new_revision_id = get_id_from_response(product.compose_close_and_new_payload().close_and_new())
    ProductAssertionSteps(product).check_object_has_new_to_date_after_close_and_new()

    product.get_by_id(id_=new_revision_id)
    ProductAssertionSteps(product).validate_get_response_is_correct(
        expected_response=product.generate_expected_response_after_close_and_new())


@pytestrail.case('C2688')
@pytest.mark.smoke
def test_delete_product():
    product = Products()

    product.compose_create_payload().create()
    ProductAssertionSteps(product).check_post_response_is_successfully()

    product.delete()
    ProductAssertionSteps(product).check_object_is_deleted_successfully()
