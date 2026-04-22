"""
BankAdapter abstract base class and shared data types.

All bank-specific scrapers must inherit from BankAdapter and implement:
    - login(page, credentials) — bank-specific login flow
    - navigate_to_transactions(page) — go to the transaction list page
    - extract_transactions(page) -> List[RawTransaction] — parse DOM
    - logout(page) (optional — default is a no-op)

The interface is intentionally identical to the LionExpressPay reference
so adapters can be reused across both systems.
"""
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from datetime import datetime
from typing import List, Optional

from playwright.async_api import Page


@dataclass
class RawTransaction:
    """Universal transaction format extracted from any bank.

    Attributes:
        payment_id: Stable unique identifier for deduplication.
                    Use a hash of (date + description + amount) when the bank
                    does not expose its own transaction ID.
        amount: Transfer amount in JPY (positive = deposit, always > 0 here).
        date: Date/time the transaction was posted.
        depositor_name: Full name string as shown by the bank.
                        For matching, this should include the reference number
                        embedded by the customer (e.g. "ヤマダ タロウ 1234567").
        memo: Optional additional text from the bank (free-form remarks field).
    """

    payment_id: str
    amount: int
    date: datetime
    depositor_name: str
    memo: str = ''


@dataclass
class BankCredentials:
    """Credentials loaded from bank_accounts.scrape_credentials_json.

    Attributes:
        username: Login ID / customer number used on the bank's login form.
        password: Login password.
        totp_secret: Base32 TOTP secret for 2FA (optional).  When present,
                     adapters should generate a one-time code with pyotp.
        extra: Dict of bank-specific extra fields such as birthdate,
               contract_code, or security answers.
    """

    username: str
    password: str
    totp_secret: Optional[str] = None
    extra: dict = field(default_factory=dict)


class BankAdapter(ABC):
    """Abstract base for bank-specific Playwright scrapers.

    Concrete adapters override the three abstract coroutines and optionally
    override ``login_url``.  The runner instantiates the adapter, calls the
    methods in order, then calls ``logout`` in a finally block.

    Attributes:
        bank_id: Primary key of the bank_accounts row (set by runner).
        bank_name: Human-readable bank name (set by runner or subclass default).
        login_url: Override in subclass to set the starting URL.
        session_timeout_minutes: Advisory timeout for session reuse.
    """

    login_url: str = ''
    session_timeout_minutes: int = 10

    def __init__(self, bank_id: int, bank_name: str, credentials: BankCredentials) -> None:
        self.bank_id = bank_id
        self.bank_name = bank_name
        self.credentials = credentials
        # Latest observed account balance in JPY, populated by the concrete
        # adapter during extract_transactions() when the statement page exposes
        # a running-balance column. None when the adapter could not read it.
        self.current_balance: Optional[int] = None

    @abstractmethod
    async def login(self, page: Page) -> None:
        """Perform login.  Must leave the page in an authenticated state."""
        ...

    @abstractmethod
    async def navigate_to_transactions(self, page: Page) -> None:
        """Navigate to the deposit / transaction history page."""
        ...

    @abstractmethod
    async def extract_transactions(self, page: Page) -> List[RawTransaction]:
        """Extract deposit rows from the current page.

        Returns:
            List of RawTransaction objects (deposits only, amount > 0).
        """
        ...

    async def logout(self, page: Page) -> None:
        """Optionally perform an explicit logout.  Default is a no-op."""

    def __repr__(self) -> str:
        return f'<{self.__class__.__name__} bank_id={self.bank_id} bank={self.bank_name!r}>'
