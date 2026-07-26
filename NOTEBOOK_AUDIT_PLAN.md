# Notebook Requirement Audit Plan

> Status: review document only. This file records the comparison between the handwritten requirements and the current Laravel implementation. No application-code changes are proposed by this document.

## 1. Objective

Compare the requirements shown in `a1.jpg`–`a4.jpg` and `round.jpg` with the current system behavior for:

- Cash In and Cash Out accounting direction
- Pay Account versus Bank Account fee behavior
- Exchange direction and password/PIN notes
- Fixed and percentage fees
- MMK fee rounding
- Owner, Cashier, and Employee menu and transaction permissions

Source files:

- `C:/Users/User/Downloads/a1.jpg`
- `C:/Users/User/Downloads/a2.jpg`
- `C:/Users/User/Downloads/a3.jpg`
- `C:/Users/User/Downloads/a4.jpg`
- `C:/Users/User/Downloads/round.jpg`

## 2. Source Requirements From the Notebook

### 2.1 Cash In

The visible rule in `a3.jpg` is:

| Ledger | Expected movement | Status |
|---|---:|---|
| KPay / Pay account | `-100,000` | Matched for the transaction amount |
| Physical cash / employee float | `+100,000` | Matched for Employee flow |
| Fee amount | Additional movement is unclear | Needs confirmation |

The Cash In flow also appears to distinguish an account used for the customer payment from a Bank Account. The exact fee payer and whether received cash includes the fee are not legible enough to treat as final policy.

### 2.2 Cash Out

The visible rule in `a4.jpg` is:

| Ledger | Expected movement | Status |
|---|---:|---|
| Physical cash / float | `-100,000` | Matched for Employee flow |
| KPay / Pay account | `+100,000` | Matched for the transaction amount |
| Fee amount | Whether cash paid is amount, amount plus fee, or amount less fee is unclear | Needs confirmation |

The current owner screen labels the cash side as main-vault cash, so the owner path must be treated separately from employee float accounting.

### 2.3 Fee Categories

`a1.jpg` and `a2.jpg` show separate notes for:

- Account / Pay Account
- Bank Account
- Cash In percentage
- Cash Out percentage
- Bank fee or additional fee
- Fixed plus percentage fee behavior

The notebook does not clearly define whether Pay and Bank are account categories, companies, service types, or separate fee ledgers.

### 2.4 Exchange

`a1.jpg` shows Exchange branches involving Pay/KPay and Bank Account, plus a password/PIN note. The current screenshot is not detailed enough to determine whether Exchange means:

- one customer account with a currency direction; or
- a transfer between a Pay Account and a Bank Account; or
- separate Pay-to-Bank and Bank-to-Pay operations.

This requirement is therefore marked `Needs confirmation`.

### 2.5 MMK Rounding

`round.jpg` defines this rule:

1. Calculate the raw fee.
2. Take the lower 100-MMK base.
3. If the remainder is `<= 20`, round down to the base.
4. If the remainder is `> 20`, round up to the next 100 MMK.
5. Apply a minimum fee of `100 MMK` for any positive raw fee.
6. A zero raw fee remains zero.

Examples from the image:

| Raw fee | Expected fee |
|---:|---:|
| `120.0` | `100` |
| `120.1` | `200` |
| `130.0` | `200` |
| `199.99` | `200` |
| `1020.0` | `1000` |
| `1020.1` | `1100` |
| `50.0` | `100` minimum |
| `20.0` | `100` minimum |
| `0.0` | `0` |

## 3. Current System Findings

### 3.1 Matched Behavior

| Requirement | Current implementation | Status |
|---|---|---|
| Employee Cash In debits the selected KPay account | `TransactionService::createCashIn()` debits the account at `app/Services/TransactionService.php:120-124` | Matched |
| Employee Cash In adds received notes and balance to the float | Received denominations and amount are added at `app/Services/TransactionService.php:160-161` | Matched |
| Employee Cash Out deducts physical cash from the float | Float notes and balance are deducted at `app/Services/TransactionService.php:286-287` | Matched |
| Employee Cash Out credits the selected account | Account balance is incremented at `app/Services/TransactionService.php:257` | Matched |
| Positive fee rounding follows the notebook examples | `Money::roundMmkFee()` uses the `<= 20` threshold and `100 MMK` minimum at `app/Support/Money.php:24-46` | Matched |
| Cashier is not allowed to create transaction entries | Transaction routes are limited to owner/employee at `routes/web.php:26-35`; API guards cashier creation | Matched |

### 3.2 P0 — Accounting Mismatches

#### Owner Cash Out does not update the main vault

- The owner Cash Out UI displays `Physical cash paid from main vault` at `resources/js/pages/transactions/CashOut.vue:190`.
- `TransactionService::createCashOut()` only performs employee float deduction when denominations are present at `app/Services/TransactionService.php:282-300`.
- There is no `CashDenominationRepository::recordBulk('vault_out', ...)` call in the owner Cash Out path.

**Impact:** The UI promises a main-vault cash decrease, but the physical-vault ledger is unchanged for owner Cash Out.

**Status:** `Mismatch` — P0 if owner Cash Out is intended to participate in physical cash reconciliation.

#### Fee is not included in physical cash movement

- Cash In uses `amount_received` and float movement independently from `customer_fee` at `app/Services/TransactionService.php:61-161`.
- Cash Out deducts exactly the transaction amount from an employee float at `app/Services/TransactionService.php:286-287`.
- The fee is credited to `fee_account_id` only when that ID is supplied at `app/Services/TransactionService.php:299` and `app/Services/TransactionService.php:650-656`.

**Impact:** If the notebook means that the customer pays the fee in cash, the current physical cash result is incomplete. The exact correction cannot be safely chosen until the fee payer rule is confirmed.

**Status:** `Needs confirmation`, with P0 impact after the fee payer rule is decided.

### 3.3 P1 — Fee and Business-Rule Mismatches

#### Pay Account and Bank Account do not have explicit fee categories

- The database stores fee values by `service_type_id` and deposit/withdraw direction at `database/migrations/2026_07_01_000001_create_ngwe_lwe_core_schema.php:106-120`.
- `TransactionFeeCalculator` resolves one tier by service type and amount at `app/Services/TransactionFeeCalculator.php:35-56`.
- There is no explicit `account_category`, `pay_account_fee`, or `bank_account_fee` field.

**Status:** `Needs confirmation`. The current model can approximate the requirement by creating separate service types, but it does not enforce Pay-versus-Bank fee semantics as a first-class rule.

#### Fee display and ledger meaning are not explicit enough

- Transaction pages display a generic `Fee (commission tier)` rather than identifying who pays it or where it is posted.
- Cash In and Cash Out review summaries show amount and fee, but do not show a final net physical-cash result when fee handling is involved.

**Status:** `Mismatch` for clarity; P1 when the fee affects real cash movement.

#### Exchange does not expose the notebook's Pay-to-Bank / Bank-to-Pay choices

- The current page has one account selector and a currency selector at `resources/js/pages/transactions/Exchange.vue:126-160`.
- The backend stores one account, one amount, one currency, and one exchange rate in `TransactionService::createExchange()`.
- The current UI does not ask for source account and destination account separately.

**Status:** `Needs confirmation` because the handwritten Exchange direction is ambiguous.

### 3.4 P2 — Security and HCI/UI/UX Mismatches

#### No transaction-level Password/PIN confirmation

- Cash In, Cash Out, and Exchange submit using the authenticated session and transaction form data.
- No transaction request or service currently requires a password/PIN before confirmation.
- Employee PIN is used for float activation, but not for transaction confirmation.

**Status:** `Mismatch` if the notebook requires Password/PIN for Exchange or high-risk transactions; otherwise `Needs confirmation`.

#### Review summaries need net movement lines

Current Cash In review shows:

- KPay account debited
- Cash received
- Physical cash added to float
- Change given

Current Cash Out review shows:

- KPay balance increased
- Physical cash paid from float or main vault
- Fee

The summaries still do not identify whether fee is part of the physical cash total or a separate ledger posting.

**Status:** `Mismatch` for business clarity, even where the underlying transaction amount is correct.

#### Role menu behavior

- Owner sees Overview, Cash In, Cash Out, Transfer, and Exchange.
- Employee sees Counter, My Float, Cash In, Cash Out, Transfer, and Exchange.
- Cashier sees Overview only and reviews pending Cash In through the existing cashier workflow.

The role separation is consistent with the current route permissions at `resources/js/layouts/BankLayout.vue:59-65` and `routes/web.php:26-48`.

**Status:** `Matched` for the current role model; the cashier review surface should be validated separately against the intended notebook workflow.

## 4. Unclear Requirements Requiring Product Confirmation

These items should not be implemented by inference:

1. Is customer fee paid in physical cash, deducted from KPay, or posted only to a fee account?
2. For Cash In, is physical cash increase `amount`, `amount + fee`, or the manually entered received amount?
3. For Cash Out, is physical cash decrease `amount`, `amount + fee`, or another net amount?
4. Is `fee_account_id` mandatory for fee-bearing transactions?
5. Are Pay Account and Bank Account separate account categories, service types, companies, or fee ledgers?
6. Does Password/PIN apply only to Exchange, or also Cash In/Out confirmation and cashier approval?
7. Does Exchange require one account with a currency direction, or two explicit accounts for Pay-to-Bank and Bank-to-Pay?

## 5. Recommended Next Phase

1. Lock the P0 accounting rules for owner vault, employee float, and fee movement.
2. Confirm the fee payer and fee-ledger behavior with one worked example such as `100,000` and `0.2%`.
3. Confirm the Password/PIN scope and whether it is a login password or transaction PIN.
4. Confirm Exchange source/destination account behavior.
5. Only after those decisions, prepare a separate implementation plan covering schema, API validation, transaction service, UI review lines, migrations, and regression tests.

## 6. Validation Notes

Existing implementation checks already passed before this report was created:

- PHPUnit: `122 tests, 605 assertions`
- Vue TypeScript check: passed
- Vite production build: passed

Those checks validate the current implementation, not the unresolved notebook business rules above.
