"""Secrets at rest. Values are sealed with libsodium secretbox and stored as
`v1:<base64(nonce + ciphertext)>`. The PHP side uses sodium_crypto_secretbox
with the same key file, so either side can read what the other wrote."""
import base64
import os

from nacl.secret import SecretBox

PREFIX = "v1:"


class SecretError(RuntimeError):
    pass


def _box() -> SecretBox:
    path = os.environ.get("APP_SECRET_KEY_FILE", "/run/secrets/app_secret_key")
    try:
        with open(path, encoding="ascii") as handle:
            key = bytes.fromhex(handle.read().strip())
    except (OSError, ValueError) as err:
        raise SecretError(f"cannot read the secret key file: {type(err).__name__}") from err
    if len(key) != SecretBox.KEY_SIZE:
        raise SecretError("the secret key file must hold 64 hex characters")
    return SecretBox(key)


def encrypt(plaintext: str) -> str:
    sealed = _box().encrypt(plaintext.encode("utf-8"))  # nonce + ciphertext
    return PREFIX + base64.b64encode(bytes(sealed)).decode("ascii")


def decrypt(value: str) -> str:
    if not value:
        return ""
    if not value.startswith(PREFIX):
        raise SecretError("unknown secret format")
    try:
        return _box().decrypt(base64.b64decode(value[len(PREFIX):])).decode("utf-8")
    except Exception as err:  # wrong key or corrupted value
        raise SecretError("cannot decrypt the stored secret; was the key file replaced?") from err
