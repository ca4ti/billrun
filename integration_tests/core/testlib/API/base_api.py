from typing import Optional

import allure
from requests import Response

from core.common.helpers.utils import dumps_values
from core.common.helpers.api_helpers import get_id_from_response
from core.connectors.api_client import APIClient


class BaseAPI(APIClient):
    """
    suitable only for 5.x API version
    """

    def __init__(self, path: str):
        super().__init__()
        self.path = path
        self.create_payload = None
        self.update_payload = None
        self.close_payload = None
        self.close_and_new_payload = None
        self.close_and_new_response = None
        self.update_response = None
        self.delete_response = None
        self.create_response = None
        self.close_response = None
        self.get_response = None

    @allure.step('Create by API')
    def create(self, payload: dict = None) -> Response:
        payload = payload or self.create_payload
        if payload:
            payload = self._add_update_key(payload)
        self.create_response = self.post(f'{self.path}/create', data=dumps_values(payload))

        return self.create_response

    @allure.step('Get by ID')
    def get_by_id(self, id_: str = None) -> Response:
        id_ = id_ or get_id_from_response(self.create_response)
        self.get_response = self.get(
            f"{self.path}/get", params=dumps_values({'query': {'_id': id_}}))

        return self.get_response

    def get_all(self) -> Response:
        self.get_response = self.get(
            f"{self.path}/get", params=dumps_values({'query': {}}))

        return self.get_response

    def get_by_query(self, **query) -> Response:
        self.get_response = self.get(
            f"{self.path}/get", params=dumps_values({'query': query}))

        return self.get_response

    @allure.step('Update by API')
    def update(self, id_: str = None, payload: dict = None) -> Response:
        payload = payload or self.update_payload
        payload = self._modify_payload(payload, id_)

        self.update_response = self.post(f'{self.path}/update', data=payload)

        return self.update_response

    @allure.step('Delete by API')
    def delete(self, id_: str = None, **kwargs) -> Response:
        id_ = id_ or get_id_from_response(self.create_response)
        self.delete_response = self.post(
            f"{self.path}/delete", data=dumps_values({'query': {'_id': id_}}), **kwargs)

        return self.delete_response

    @allure.step('Close by API')
    def close(self, id_: str = None, payload: dict = None) -> Response:
        payload = payload or self.close_payload
        payload = self._modify_payload(payload, id_)

        self.close_response = self.post(f'{self.path}/close', data=payload)

        return self.close_response

    @allure.step('Close and new by API')
    def close_and_new(self, id_: str = None, payload: dict = None) -> Response:
        payload = payload or self.close_and_new_payload
        payload = self._modify_payload(payload, id_)

        self.close_and_new_response = self.post(f'{self.path}/closeandnew', data=payload)

        return self.close_and_new_response

    def _modify_payload(self, payload: Optional[dict], id_: Optional[str]) -> dict:
        if payload:
            payload = self._add_update_key(payload)
        return self._add_id_to_data_payload(id_, payload)

    def _add_id_to_data_payload(self, id_: str = None, payload: dict = None) -> dict:
        id_ = id_ or get_id_from_response(self.create_response)
        if not payload:
            payload = {}
        payload.update({'query': {'_id': id_}})
        payload = dumps_values(payload)

        return payload

    @staticmethod
    def _add_update_key(payload: dict) -> dict:
        result = {'update': payload}
        return result