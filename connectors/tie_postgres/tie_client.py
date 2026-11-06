"""Client for the web service of connectors/tie (see its README.md).

Routes used: GET /v1/status and POST /v1/tools/<tool>. Every request carries
the connector bearer token."""
import time

import requests


class TieError(Exception):
    """A TIE connector request failed. The message is safe to show and log."""


class TieNotReady(TieError):
    """The connector is still scanning AD; try again later."""


class TieClient:
    def __init__(self, url: str, token: str, timeout: int = 600, sleep=time.sleep):
        self.url = (url or "").strip().rstrip("/")
        self.timeout = timeout
        self._sleep = sleep
        self.session = requests.Session()
        self.session.headers.update({"Authorization": f"Bearer {token}",
                                     "Content-Type": "application/json",
                                     "Accept": "application/json"})

    def _send(self, method: str, path: str, body=None, timeout=None):
        try:
            return self.session.request(method, f"{self.url}{path}", json=body,
                                        timeout=(10, timeout or self.timeout))
        except requests.exceptions.RequestException as err:
            raise TieError(f"Cannot reach the TIE connector at {self.url}: "
                           f"{type(err).__name__}: {str(err)[:200]}") from err

    @staticmethod
    def _json(reply):
        try:
            data = reply.json()
        except ValueError:
            data = None
        if reply.status_code == 401:
            raise TieError("The TIE connector rejected the token (HTTP 401).")
        if not isinstance(data, dict):
            raise TieError(f"The TIE connector returned HTTP {reply.status_code} without a JSON body.")
        return data

    def status(self) -> dict:
        """GET /v1/status. Answers 200 in every cache state."""
        reply = self._send("GET", "/v1/status", timeout=30)
        data = self._json(reply)
        if reply.status_code != 200:
            raise TieError(f"The TIE connector status call failed (HTTP {reply.status_code}).")
        return data

    def wait_until_ready(self, max_wait_seconds: int, on_wait=None) -> dict:
        """Poll the status until the AD snapshot can be queried."""
        deadline = time.monotonic() + max_wait_seconds
        while True:
            status = self.status()
            state = status.get("state")
            if state == "ready":
                return status
            if state == "failed":
                raise TieError("The TIE connector's AD scan failed: "
                               f"{status.get('error') or 'no detail'}")
            if time.monotonic() >= deadline:
                raise TieNotReady(f"The TIE connector is still {state} after {max_wait_seconds}s "
                                  f"({status.get('objectsFetched')} objects fetched).")
            if on_wait:
                on_wait(status)
            self._sleep(5)

    def tool(self, name: str, arguments: dict | None = None, attempts: int = 60) -> dict:
        """POST /v1/tools/<name>. Waits out 202/503 (scan in progress) using
        Retry-After. A 200 reply that carries `error` is a failure too."""
        for _ in range(attempts):
            reply = self._send("POST", f"/v1/tools/{name}", arguments or {})
            if reply.status_code in (202, 503):
                try:
                    delay = min(30, max(1, int(reply.headers.get("Retry-After") or 3)))
                except ValueError:
                    delay = 3
                self._sleep(delay)
                continue
            data = self._json(reply)
            if reply.status_code != 200 or data.get("error"):
                detail = data.get("message") or data.get("error") or ""
                raise TieError(f"TIE tool {name} failed (HTTP {reply.status_code}): {str(detail)[:400]}")
            return data
        raise TieNotReady(f"TIE tool {name} was not available after {attempts} attempts; "
                          "the connector is still refreshing its AD snapshot.")
