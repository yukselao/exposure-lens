"""Tenable Security Center REST client (read-only).

Adapted from jira-test (ui/fastapi/app/services/tenable_sc.py): same API-key
header and the same cumulative `vulndetails` analysis request. Added here:
retries with backoff, and a check of `error_code` in the response body.

API reference: https://docs.tenable.com/security-center/api/index.htm
"""
import time

import requests
import urllib3


class ScError(Exception):
    """A Security Center request failed. The message is safe to show and log."""


class TenableSC:
    RETRY_DELAYS = (2, 5, 15)  # seconds before the 2nd, 3rd and 4th attempt
    CONNECT_TIMEOUT = 10       # seconds; an unreachable host should fail fast

    def __init__(self, url: str, access_key: str, secret_key: str, verify_ssl: bool = True,
                 timeout: int = 120, sleep=time.sleep):
        self.url = (url or "").strip().rstrip("/")
        if self.url.endswith("/rest"):
            self.url = self.url[: -len("/rest")]
        self.timeout = timeout
        self._sleep = sleep
        self.session = requests.Session()
        self.session.verify = bool(verify_ssl)
        self.session.headers.update({
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-APIKey": f"accesskey={access_key}; secretkey={secret_key}",
        })
        if not verify_ssl:
            urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

    def _request(self, method: str, path: str, body=None, timeout=None, on_retry=None):
        """Return the `response` member of the SC reply, or raise ScError."""
        last_error = None
        for attempt in range(len(self.RETRY_DELAYS) + 1):
            if attempt:
                delay = self.RETRY_DELAYS[attempt - 1]
                if on_retry:
                    on_retry(attempt, delay, last_error)
                self._sleep(delay)
            try:
                reply = self.session.request(method, f"{self.url}{path}", json=body,
                                             timeout=(self.CONNECT_TIMEOUT, timeout or self.timeout))
            except requests.exceptions.SSLError as err:
                raise ScError("TLS verification failed for Security Center. Install its CA "
                              f"certificate or turn off SSL verification. ({_short(err)})") from err
            except requests.exceptions.RequestException as err:
                last_error = f"{type(err).__name__}: {_short(err)}"
                continue
            if reply.status_code in (429, 500, 502, 503, 504):
                last_error = f"HTTP {reply.status_code}"
                continue
            return self._parse(reply)
        raise ScError(f"Security Center did not answer after {len(self.RETRY_DELAYS) + 1} "
                      f"attempts ({last_error}).")

    @staticmethod
    def _parse(reply):
        try:
            data = reply.json()
        except ValueError:
            data = None
        if not isinstance(data, dict):
            raise ScError(f"Security Center returned HTTP {reply.status_code} without a JSON body. "
                          "Check the URL.")
        code = data.get("error_code")
        message = str(data.get("error_msg") or "").strip()
        if reply.status_code in (401, 403) and not message:
            message = "access denied"
        if reply.status_code != 200 or code not in (0, "0", None):
            raise ScError(f"Security Center error (HTTP {reply.status_code}, code {code}): "
                          f"{message[:500]}")
        return data.get("response")

    # ---- calls ---------------------------------------------------------------
    def current_user(self) -> dict:
        """GET /rest/currentUser: proves the URL and the API keys work."""
        return self._request("GET", "/rest/currentUser?fields=id,username,role", timeout=20) or {}

    def repositories(self) -> list:
        rows = self._request("GET", "/rest/repository?fields=id,name,dataFormat", timeout=30) or []
        return [{"id": str(r.get("id")), "name": r.get("name") or "",
                 "data_format": r.get("dataFormat") or ""} for r in rows]

    def plugin_families(self) -> list:
        rows = self._request("GET", "/rest/pluginFamily?fields=id,name", timeout=30) or []
        return [{"id": str(r.get("id")), "name": r.get("name") or ""} for r in rows]

    def cumulative_vulns(self, filters: list, start_offset: int, end_offset: int, on_retry=None) -> dict:
        """POST /rest/analysis, tool `vulndetails`, cumulative database.
        Returns rows [start_offset, end_offset) with `totalRecords`."""
        body = {
            "query": {
                "name": "", "description": "", "context": "", "status": -1,
                "createdTime": 0, "modifiedTime": 0, "groups": [],
                "type": "vuln", "tool": "vulndetails", "sourceType": "cumulative",
                "startOffset": start_offset, "endOffset": end_offset,
                "filters": filters, "vulnTool": "vulndetails",
            },
            "sourceType": "cumulative", "columns": [], "type": "vuln",
        }
        response = self._request("POST", "/rest/analysis", body, on_retry=on_retry)
        if not isinstance(response, dict) or not isinstance(response.get("results"), list):
            raise ScError("Security Center returned an analysis reply without results.")
        return response


def _short(err) -> str:
    return str(err)[:200]
