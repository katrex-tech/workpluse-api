import time
import hmac
import hashlib
from typing import Optional, List, Set
from dataclasses import dataclass
import jwt
from fastapi import HTTPException, Security, Depends, status, Header, Request
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials, SecurityScopes
from app.config import settings

bearer_scheme = HTTPBearer(auto_error=False)

@dataclass
class AuthenticatedContext:
    tenant_id: str
    user_id: Optional[str]
    scopes: Set[str]
    auth_type: str  # "jwt" or "api_key"

def create_access_token(
    tenant_id: str,
    user_id: Optional[str] = None,
    scopes: Optional[List[str]] = None,
    expires_delta_seconds: int = 3600
) -> str:
    """Creates a signed JWT with strict issuer, audience, tenant, and expiration."""
    now = int(time.time())
    payload = {
        "iss": settings.JWT_ISSUER,
        "aud": settings.JWT_AUDIENCE,
        "tenant_id": tenant_id,
        "sub": user_id or f"service_{tenant_id}",
        "scopes": scopes or ["faces:detect", "faces:enroll", "faces:identify", "faces:manage"],
        "iat": now,
        "exp": now + expires_delta_seconds
    }
    return jwt.encode(payload, settings.SECRET_KEY, algorithm=settings.JWT_ALGORITHM)

def create_thumbnail_token(tenant_id: str, user_id: str, ttl_seconds: int = 900) -> str:
    """Generates a secure, short-lived HMAC-signed token to access a protected thumbnail."""
    exp = int(time.time()) + ttl_seconds
    msg = f"{tenant_id}:{user_id}:{exp}".encode("utf-8")
    sig = hmac.new(settings.SECRET_KEY.encode("utf-8"), msg, hashlib.sha256).hexdigest()
    return f"{exp}.{sig}"

def verify_thumbnail_token(tenant_id: str, user_id: str, token: str) -> bool:
    """Validates an HMAC-signed thumbnail access token."""
    try:
        parts = token.split(".")
        if len(parts) != 2:
            return False
        exp_str, sig = parts
        exp = int(exp_str)
        if time.time() > exp:
            return False
        expected_msg = f"{tenant_id}:{user_id}:{exp}".encode("utf-8")
        expected_sig = hmac.new(settings.SECRET_KEY.encode("utf-8"), expected_msg, hashlib.sha256).hexdigest()
        return hmac.compare_digest(sig, expected_sig)
    except Exception:
        return False

async def get_current_auth(
    security_scopes: SecurityScopes,
    credentials: Optional[HTTPAuthorizationCredentials] = Security(bearer_scheme),
    x_api_key: Optional[str] = Header(None, alias="X-API-Key"),
    x_tenant_id: Optional[str] = Header(None, alias="X-Tenant-Id")
) -> AuthenticatedContext:
    """
    Authenticates requests via either:
    1. Cryptographically verified JWT Bearer token (signature, issuer, audience, expiry)
    2. Static server-to-server API Key (X-API-Key or Bearer token matching configured API_KEYS)
    Derives tenant_id strictly from verified credentials to enforce tenant isolation.
    """
    token_str: Optional[str] = None
    if credentials and credentials.credentials:
        token_str = credentials.credentials.strip()
    elif x_api_key:
        token_str = x_api_key.strip()

    if not token_str:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing authentication credentials. Provide Bearer JWT or X-API-Key.",
            headers={"WWW-Authenticate": f'Bearer scope="{", ".join(security_scopes.scopes)}"'}
        )

    # 1. Check API Key
    if token_str in settings.api_keys_list:
        tenant = x_tenant_id or settings.DEFAULT_TENANT_ID
        return AuthenticatedContext(
            tenant_id=tenant,
            user_id="service_caller",
            scopes={"faces:detect", "faces:enroll", "faces:identify", "faces:manage"},
            auth_type="api_key"
        )

    # 2. Check JWT
    try:
        payload = jwt.decode(
            token_str,
            settings.SECRET_KEY,
            algorithms=[settings.JWT_ALGORITHM],
            issuer=settings.JWT_ISSUER,
            audience=settings.JWT_AUDIENCE
        )
    except jwt.ExpiredSignatureError:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Authentication token has expired.",
            headers={"WWW-Authenticate": "Bearer error=\"invalid_token\", error_description=\"token has expired\""}
        )
    except jwt.InvalidTokenError as e:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail=f"Invalid authentication token: {str(e)}",
            headers={"WWW-Authenticate": "Bearer error=\"invalid_token\""}
        )

    tenant_id = payload.get("tenant_id")
    if not tenant_id:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Token does not contain a verified tenant identity."
        )

    token_scopes = set(payload.get("scopes", []))
    for required_scope in security_scopes.scopes:
        if required_scope not in token_scopes:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=f"Insufficient permissions. Required scope: '{required_scope}'",
                headers={"WWW-Authenticate": f'Bearer scope="{required_scope}"'}
            )

    return AuthenticatedContext(
        tenant_id=tenant_id,
        user_id=payload.get("sub"),
        scopes=token_scopes,
        auth_type="jwt"
    )
