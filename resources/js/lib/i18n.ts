import { ref } from 'vue';

export type Locale = 'en' | 'mm';

const STORAGE_KEY = 'ngwe-lwe:locale';

const cp1252Bytes: Record<number, number> = {
    0x20ac: 0x80,
    0x201a: 0x82,
    0x192: 0x83,
    0x201e: 0x84,
    0x2026: 0x85,
    0x2020: 0x86,
    0x2021: 0x87,
    0x2c6: 0x88,
    0x2030: 0x89,
    0x160: 0x8a,
    0x2039: 0x8b,
    0x152: 0x8c,
    0x17d: 0x8e,
    0x2018: 0x91,
    0x2019: 0x92,
    0x201c: 0x93,
    0x201d: 0x94,
    0x2022: 0x95,
    0x2013: 0x96,
    0x2014: 0x97,
    0x2dc: 0x98,
    0x2122: 0x99,
    0x161: 0x9a,
    0x203a: 0x9b,
    0x153: 0x9c,
    0x17e: 0x9e,
    0x178: 0x9f,
};

function repairMojibake(value: string): string {
    if (
        typeof TextDecoder === 'undefined' ||
        !/[\u00c0-\u00ff\u20ac\u2013\u2014]/.test(value)
    ) {
        return value;
    }

    const bytes: number[] = [];

    for (const character of value) {
        const code = character.codePointAt(0) ?? 0;
        const byte = code <= 0xff ? code : cp1252Bytes[code];

        if (byte === undefined) {
            return value;
        }

        bytes.push(byte);
    }

    try {
        return new TextDecoder('utf-8', { fatal: true }).decode(
            new Uint8Array(bytes),
        );
    } catch {
        return value;
    }
}

const messages: Record<Locale, Record<string, string>> = {
    en: {
        'brand.name': 'Doctor Phone',
        'brand.operations': 'Doctor Phone Operations',
        'language.english': 'English',
        'language.myanmar': 'Myanmar',
        'role.admin': 'Admin',
        'role.cashier': 'Cashier',
        'role.teller': 'Teller',
        'section.banking': 'Banking',
        'section.office': 'Office',
        'section.admin': 'Admin',
        'nav.overview': 'Overview',
        'nav.counter': 'Counter',
        'nav.myFloat': 'My Float',
        'nav.cashIn': 'Cash In',
        'nav.cashOut': 'Cash Out',
        'nav.transfer': 'Transfer',
        'nav.exchange': 'Exchange',
        'nav.accounts': 'Accounts',
        'nav.vault': 'Main Vault',
        'nav.reconcile': 'Reconciliation',
        'nav.reports': 'Reports',
        'nav.settings': 'Settings',
        'common.announcement': 'Announcement',
        'common.openMenu': 'Open menu',
        'common.closeMenu': 'Close menu',
        'common.desktopNavigation': 'Desktop navigation',
        'common.mobileNavigation': 'Mobile navigation',
        'common.language': 'Language',
        'common.expandMenu': 'Expand menu',
        'common.collapseMenu': 'Collapse menu',
        'common.pendingNotifications': 'pending notifications',
        'common.noPendingNotifications': 'No pending notifications',
        'common.signOut': 'Sign out',
        'common.secureConnection': 'Secure connection',
        'common.home': 'Home',
        'common.back': 'Back',
        'common.cancel': 'Cancel',
        'common.confirm': 'Confirm',
        'common.clear': 'Clear',
        'common.choose': 'Choose',
        'common.select': 'Select',
        'common.change': 'Change',
        'common.search': 'Search',
        'common.noResults': 'No matching result.',
        'common.yes': 'Yes',
        'common.no': 'No',
        'common.noAccounts': 'No accounts yet.',
        'common.noTransactions': 'No transactions yet today.',
        'common.review': 'Review',
        'common.reviewSlip': 'Review slip',
        'common.continueReview': 'Continue to review',
        'common.backToEdit': 'Back to edit',
        'common.recording': 'Recording…',
        'common.submitting': 'Submitting…',
        'common.verifying': 'Verifying…',
        'common.authorise': 'Authorise',
        'common.yourPin': 'Your PIN',
        'common.pinHint': '4–8 digits. Three wrong attempts locks the action.',
        'common.close': 'Close',
        'status.active': 'Counter open',
        'status.floatToCount': 'Float to count',
        'status.withCashier': 'With cashier',
        'status.counterClosed': 'Counter closed',
        'status.awaitingCashier': 'Awaiting cashier',
        'status.completed': 'Completed',
        'status.cancelled': 'Cancelled',
        'dashboard.title': 'Overview',
        'dashboard.cashFlow': 'Cash In vs. Cash Out',
        'dashboard.downloadPdf': 'Download PDF',
        'dashboard.oneYear': '1 Year',
        'dashboard.sixMonths': '6 Months',
        'dashboard.oneMonth': '1 Month',
        'dashboard.oneWeek': '1 Week',
        'dashboard.accounts': 'Accounts',
        'dashboard.liveBalances': 'Live balances',
        'dashboard.totalAccounts': 'accounts',
        'dashboard.totalBalance': 'total balance',
        'dashboard.accountName': 'Account',
        'dashboard.accountNumber': 'Phone / account no.',
        'dashboard.service': 'Service',
        'dashboard.balance': 'Balance',
        'dashboard.feeAccount': 'Fee account',
        'dashboard.noAccounts': 'No accounts under this company yet.',
        'dashboard.myFloat': 'My Float',
        'dashboard.activeFloats': 'Active Floats',
        'dashboard.goToFloats': 'Go to Floats',
        'dashboard.officeWide': 'Office-wide',
        'dashboard.holder': 'Holder',
        'dashboard.onHand': 'On hand',
        'dashboard.recentHistory': 'Recent History',
        'dashboard.newEntry': 'New Entry',
        'dashboard.reviewMode': 'Review mode',
        'dashboard.configurationMode': 'Configuration mode',
        'dashboard.pendingCashIn': 'Pending Cash In',
        'dashboard.pendingReviewHint':
            'Confirm only after received cash, handoff and change agree.',
        'dashboard.received': 'Received',
        'dashboard.handoff': 'Handoff',
        'dashboard.settlementCash': 'Settlement cash',
        'dashboard.mainVaultCash': 'Main vault cash',
        'dashboard.change': 'Change',
        'dashboard.action': 'Action',
        'dashboard.noPending': 'No Cash In waiting for confirmation.',
        'dashboard.noFloats': 'No active floats right now.',
        'dashboard.pending': 'pending',
        'dashboard.confirm': 'Confirm',
        'dashboard.cancel': 'Cancel',
        'dashboard.unableUpdate': 'Unable to update this Cash In.',
        'dashboard.denominationReview': 'Denomination review',
        'dashboard.verifyBeforeConfirm':
            'Verify denominations before confirming',
        'dashboard.denominationReviewHint':
            'Check customer cash, Teller change, and the exact Cashier handoff before confirming.',
        'dashboard.expectedHandoff': 'Expected handoff',
        'dashboard.expectedSettlement': 'Expected settlement',
        'dashboard.noDenomination': 'No denomination recorded.',
        'dashboard.denominationBalanced': 'Denomination balanced',
        'dashboard.denominationMismatch': 'Denomination mismatch',
        'dashboard.confirmCashInWithPin': 'Authorise Cash In',
        'dashboard.confirmCashInWithPinHint':
            'Enter your Cashier PIN to post this handoff into the main vault.',
        'transaction.cashIn': 'Cash In',
        'transaction.cashOut': 'Cash Out',
        'transaction.transfer': 'Transfer',
        'transaction.exchange': 'Exchange',
        'transaction.enterDetails': 'Enter Cash In details',
        'transaction.enterCashOutDetails': 'Enter Cash Out details',
        'transaction.enterTransferDetails': 'Enter Transfer details',
        'transaction.description': 'Description',
        'transaction.fee': 'Fee',
        'transaction.feePaymentMethod': 'How will the service fee be paid?',
        'transaction.feePaymentCash': 'Cash',
        'transaction.feePaymentCashHint':
            'Include the fee in the cash movement.',
        'transaction.cashOutFeeCashOutcome':
            'Customer pays the fee in cash; fee notes are added to teller vault.',
        'transaction.cashFeeReceivedNotes': 'Cash fee received notes',
        'transaction.cashFeeReceivedHint':
            'Count the service fee cash from the customer. These notes are added to your teller vault.',
        'transaction.cashSettlement': 'Cash settlement',
        'transaction.cashSettlementHint':
            'Use one sheet for payout, cash fee received and any change returned.',
        'transaction.customerPayout': 'Customer payout',
        'transaction.customerPayoutShort': 'Payout −',
        'transaction.feeReceived': 'Fee cash received',
        'transaction.feeReceivedShort': 'Fee +',
        'transaction.changeToCustomer': 'Change to customer',
        'transaction.changeShort': 'Change −',
        'transaction.projected': 'Projected',
        'transaction.netTellerCash': 'Net teller cash',
        'transaction.fillPayout': 'Fill payout',
        'transaction.fillChange': 'Fill change',
        'transaction.fillExactDue': 'Fill exact due',
        'transaction.receivedShort': 'Received +',
        'transaction.amountDue': 'Amount due',
        'transaction.customerPaid': 'Customer paid',
        'transaction.netCashReceived': 'Net received',
        'transaction.cashInCashierSettlementHint':
            'Count all cash received together. If the customer overpays, select only the notes returned as change.',
        'transaction.customer': 'Customer',
        'transaction.cashInCashierCount': 'Cash In · Cashier Count',
        'transaction.cashInPlusCashFee': 'Cash In + cash fee',
        'transaction.cashInFeePaidByAccount':
            'Cash In only · fee paid by account',
        'transaction.closeCashInReview': 'Close Cash In review',
        'transaction.receivedMinusChangeMustEqual':
            'Received − Change must equal',
        'transaction.rejectCashIn': 'Reject Cash In',
        'transaction.confirmWithPin': 'Confirm with PIN',
        'transaction.confirmPendingCashIn': 'Confirm Cash In',
        'transaction.confirmCashInPinHint':
            'Enter your Cashier PIN to post the counted cash into the main vault.',
        'transaction.rejectCashInPinHint':
            'Enter your Cashier PIN to reverse this pending Cash In.',
        'transaction.insufficientChangeNotes': 'Not enough change notes',
        'transaction.cashierCountsCash': 'Cashier counts the physical cash',
        'transaction.cashierCountsCashHint':
            'Enter the Cash In details only. The Cashier will count received notes and return any change before confirming the transaction.',
        'transaction.physicalCashCount': 'Physical cash count',
        'transaction.pendingCashierCount': 'Pending Cashier count',
        'transaction.cashSettlementMatched': 'Cash settlement matched',
        'transaction.cashFeeReceivedMinimumHint':
            'Count at least the fee amount received from the customer.',
        'transaction.cashOutChangeHint':
            'Count the exact change to return to the customer.',
        'transaction.projectedStockError':
            'A denomination would go below zero. Adjust payout or change notes.',
        'transaction.feePaymentAccount': 'Account',
        'transaction.feePaymentAccountHint':
            'Debit the source account and credit a fee account.',
        'transaction.feePaymentAccountIncludedHint':
            'Customer sends the fee with the amount into the system receive account.',
        'transaction.cashOutAccountFeeHint':
            'Add the fee into the selected account to credit. No separate fee account is needed.',
        'transaction.cashOutAccountFeeDestination': 'Fee will be credited to: ',
        'transaction.feeAccount': 'Fee account',
        'transaction.chooseFeeAccount': 'Choose the fee account',
        'transaction.noFeeAccounts': 'No active fee account is configured.',
        'transaction.feeAccountRequired':
            'Choose the account that will receive this fee.',
        'transaction.feeAmount': 'Service fee',
        'transaction.commissionTier': 'commission tier',
        'transaction.transferFeeTier': 'transfer fee tier',
        'transaction.agentCommission': 'Agent commission',
        'transaction.receiveCommission': 'Receive account commission',
        'transaction.payoutCommission': 'Payout account commission',
        'transaction.customerSends': 'Customer sends',
        'transaction.systemReceives': 'System receives',
        'transaction.systemSends': 'System sends',
        'transaction.customerReceives': 'Customer receives',
        'transaction.receiveLeg': 'Receive side',
        'transaction.payoutLeg': 'Payout side',
        'transaction.payBank': 'Pay / Bank',
        'transaction.noSystemAccount':
            'No active system account is available for this company.',
        'transaction.company': 'Company',
        'transaction.accounts': 'accounts',
        'transaction.companies': 'companies',
        'transaction.chooseCompanyFirst': 'Choose the service company first.',
        'transaction.cashOutCreditCompany': 'Company to credit',
        'transaction.cashOutCreditCompanyHint':
            'Choose a company, then choose the account that will receive the Cash Out credit.',
        'transaction.cashOutFilteredAccountHint':
            'Only accounts under the selected company are shown.',
        'transaction.screenshot': 'Screenshot',
        'transaction.attachScreenshot': 'Attach screenshot',
        'transaction.screenshotHint': 'PNG, JPG, BMP, or GIF up to 4 MB.',
        'transaction.cashReceived': 'Cash received',
        'transaction.cashReceivedCustomer': 'Customer cash received',
        'transaction.cashInCountPrerequisite':
            'Choose the account and enter the Cash In amount before counting notes.',
        'transaction.cashInDenominationHint':
            "Count the customer's cash. If change is needed, count it from your Teller vault. The Cashier handoff is calculated automatically.",
        'transaction.cashInDescription':
            "Take the customer's cash, deduct the account, and hand the slip to the cashier for confirmation.",
        'transaction.afterCashierConfirmation': 'after Cashier confirmation',
        'transaction.cashOutDescription':
            'Pay cash from your teller vault and record the account credit.',
        'transaction.transferDescription':
            'Move value between accounts and settle the notes from your teller vault.',
        'transaction.exchangeDescription':
            'Record a currency exchange using the live buy and sell rates from the server.',
        'transaction.customerName': 'Customer name',
        'transaction.customerPhone': 'Customer phone',
        'transaction.cashShort':
            'Received cash is less than the Cash In amount.',
        'transaction.changeNotice':
            'Give the change from your own teller vault.',
        'transaction.floatAfterChange': 'Teller vault after change',
        'transaction.cashInConsequence':
            'The account is deducted immediately. The main vault is credited only when the cashier confirms the handoff.',
        'transaction.cashOutConsequence':
            'The exact notes are deducted from your teller vault and the account is credited.',
        'transaction.transferConsequence':
            'The source account is debited, the destination account is credited, and the exact notes are deducted from your teller vault.',
        'transaction.exchangeConsequence':
            'The exchange account is credited and the exact notes are deducted from your teller vault.',
        'transaction.completedHint':
            'Show this reference to the customer after checking the details.',
        'transaction.slip': 'Slip',
        'transaction.rate': 'Rate',
        'transaction.floatCashPaid': 'Teller vault cash paid',
        'transaction.floatShort':
            'Your teller vault does not have enough cash. Reduce the amount or ask the cashier for a top-up.',
        'transaction.cashHandedCashier': 'Cashier handoff amount',
        'transaction.changeMyVault': 'Change from my teller vault',
        'transaction.notesMainVault': 'Notes from cashier main vault',
        'transaction.notesMyVault': 'Notes from my vault',
        'transaction.accountDebit': 'KPay account to debit',
        'transaction.accountCredit': 'KPay account to credit',
        'transaction.cashOutAccountCredit': 'Account to credit',
        'transaction.exchangeAccount': 'Exchange account',
        'transaction.exchangePaymentMethod': 'Exchange payment method',
        'transaction.sourceAccount': 'Source account',
        'transaction.sourceProvider': 'Source pay/bank',
        'transaction.sourceCompany': 'Source company',
        'transaction.transferCustomerInfo': 'Customer information',
        'transaction.transferCustomerInfoHint':
            'Type the customer/beneficiary name and account number manually. System accounts are selected below.',
        'transaction.customerPayBank': 'Customer Pay/Bank',
        'transaction.customerSourceCompany': 'Customer source company',
        'transaction.sourceBeneficiaryName': 'Customer / beneficiary name',
        'transaction.sourceManualHint':
            'Enter the customer side manually: company, name, and account.',
        'transaction.sourceAccountNumber': 'Source account number',
        'transaction.customerSourceAccountNumber':
            'Customer source account number',
        'transaction.destinationCompany': 'Destination company',
        'transaction.destinationCompanyHint':
            'Choose the company first. Only accounts under that company will be shown below.',
        'transaction.destinationAccount': 'Destination account',
        'transaction.destinationAccountFilteredHint':
            'Destination account list is filtered by the selected company.',
        'transaction.systemReceiveCompany': 'System receive company',
        'transaction.systemReceiveCompanyHint':
            'Customer sends money into this system Pay/Bank company.',
        'transaction.systemReceiveAccount': 'System receive account',
        'transaction.systemPayoutCompany': 'System payout company',
        'transaction.systemPayoutCompanyHint':
            'System sends money out from this company to the customer destination.',
        'transaction.systemPayoutAccount': 'System payout account',
        'transaction.customerDestinationAccount':
            'Customer destination account',
        'transaction.destinationBeneficiaryName': 'Customer / beneficiary name',
        'transaction.customerDestinationHint':
            'Type the customer destination account number manually. The company comes from the selected system payout company.',
        'transaction.destinationAccountNumber': 'Destination account number',
        'transaction.customerDestinationAccountNumber':
            'Customer destination account number',
        'transaction.cashInAmount': 'Cash In amount',
        'transaction.amountReadingHint': 'Amount reading',
        'transaction.cashOutAmount': 'Enter Cash Out Amount',
        'transaction.cashToExchange': 'Cash to exchange',
        'transaction.transferAmount': 'Transfer amount',
        'transaction.reviewCashIn': 'Review Cash In entry',
        'transaction.reviewCashOut': 'Review Cash Out',
        'transaction.reviewHint': 'Check every line before confirming.',
        'transaction.confirmCashIn': 'Submit Cash In',
        'transaction.confirmCashOut': 'Confirm Cash Out',
        'transaction.confirmTransfer': 'Confirm Transfer',
        'transaction.confirmExchange': 'Confirm Exchange',
        'transaction.newCashIn': 'New Cash In',
        'transaction.newCashOut': 'New Cash Out',
        'transaction.cashInSubmitted': 'Cash In entry submitted',
        'transaction.cashOutSuccessful': 'Cash Out Successful',
        'transaction.awaitingCashier': 'Awaiting cashier confirmation',
        'transaction.cashierLocked':
            'Cashiers review and confirm transactions, but cannot create new entries from this screen.',
        'transaction.floatLocked':
            'Your float is not active. Receive a float from the cashier before entering transactions.',
        'transaction.goToFloats': 'Go to Floats',
        'transaction.accountDeducted': 'Account deducted',
        'transaction.accountCredited': 'Account credited',
        'transaction.amount': 'Amount',
        'transaction.status': 'Status',
        'transaction.kpayBalanceDecreased': 'KPay balance decreased',
        'transaction.kpayBalanceIncreased': 'KPay balance increased',
        'transaction.accountBalanceIncreased': 'Account balance increased',
        'transaction.mainVaultIncrease': 'Cashier main vault increase',
        'transaction.tellerVaultNetChange': 'Teller vault net change',
        'transaction.tellerDenominationChange': 'Teller denomination change',
        'transaction.cashPaidCustomer': 'Cash paid to customer',
        'transaction.countedMovement': 'Counted movement',
        'transaction.floatAfterPayout': 'Float after payout',
        'transaction.floatAfterTransfer': 'Float after transfer',
        'transaction.floatAfterExchange': 'Float after exchange',
        'transaction.direction': 'Direction',
        'transaction.sellRate': 'Sell rate',
        'transaction.buyRate': 'Buy rate',
        'transaction.mmkToThb': 'MMK to THB',
        'transaction.thbToMmk': 'THB to MMK',
        'teller.counter': 'Your counter',
        'teller.till': 'Your till',
        'teller.issued': 'Issued',
        'teller.onHandNow': 'On hand now',
        'teller.paidOutToday': 'Paid out today',
        'teller.transactionsEntered': 'transactions entered',
        'teller.recentEntries': 'Recent entries',
        'teller.floatNumber': 'Float',
        'teller.cashInNote': 'Customer hands cash; the account is debited.',
        'teller.cashOutNote':
            'Pay cash from your teller vault; the account is credited.',
        'teller.transferNote': 'Move value between accounts.',
        'teller.exchangeNote': "MMK / THB at today's rate.",
        'teller.ref': 'Ref',
        'teller.type': 'Type',
        'teller.amount': 'Amount',
        'teller.fee': 'Fee',
        'teller.status': 'Status',
        'teller.noRecentEntries':
            'Nothing entered yet. Your first transaction of the day appears here.',
        'teller.floatDescription':
            'Cash you are personally accountable for until the cashier signs it back in.',
        'teller.receiveFloatPage': 'Receive float',
        'teller.returnCashPage': 'Return cash',
        'teller.floatHistoryPage': 'Float history',
        'teller.receiveFloatDescription':
            'Review cashier-entered notes, then receive or reject with your PIN.',
        'teller.returnCashDescription':
            'Count the cash you are handing back to the cashier once, then confirm with your PIN.',
        'teller.floatHistoryDescription':
            'Review your float sessions and additional issues.',
        'teller.cashierIssuedNotes': 'Cashier issued notes',
        'teller.pendingReceiptTitle': 'Float waiting to be received',
        'teller.openReceiveFloat': 'Open Receive Float',
        'teller.pendingAdditionalFloatNotice':
            'additional float issue(s) are waiting for your review.',
        'teller.reviewNow': 'Review now',
        'teller.onHandBreakdown': 'Current note breakdown',
        'teller.myFloatReadOnlyHint':
            'This page is read-only. Use Return Cash when you hand money back to the cashier.',
        'teller.noPendingFloat': 'No float is waiting for receipt',
        'teller.noPendingFloatDescription':
            'When the cashier issues an initial or additional float, it will appear here for review and PIN confirmation.',
        'teller.additionalFloatIssues': 'Additional float issues',
        'teller.additionalReceiveHint':
            'Review the cashier-entered breakdown. Do not re-enter note quantities.',
        'teller.reviewReceive': 'Review & receive',
        'teller.receiveBeforeReturn':
            'Receive or reject all pending additional float issues before returning cash.',
        'teller.receiveBeforeReturnTitle': 'Receive your float first',
        'teller.additionalIssueHistory': 'Additional issue history',
        'teller.additionalIssueHistoryHint':
            'Read-only record of additional float issues and their final status.',
        'teller.floatHistoryReadOnly':
            'Historical float sessions are read-only. Receive and return actions live in their own menus.',
        'teller.noFloat': 'No float issued',
        'teller.askCashier':
            'Ask the cashier to issue one. Review the cashier-entered note breakdown, then receive it with your PIN to open the counter.',
        'teller.countIssued':
            'The cashier entered the issued note breakdown. Check the physical notes against it before confirming.',
        'teller.countMatch':
            'Verify the physical notes match this denomination breakdown. If anything differs, reject the receipt instead of confirming.',
        'teller.systemOnHand': 'System says on hand',
        'teller.youCounted': 'You counted',
        'teller.returnCloses':
            'Once returned, the counter closes until a new float is issued.',
        'teller.confirmCount': 'Confirm incoming float',
        'teller.confirmReturn': 'Confirm the cash you are returning',
        'teller.pinCount': 'Your PIN records that the notes match.',
        'teller.pinReturn': 'Your PIN records the return count.',
        'teller.float': 'My float',
        'teller.floatOnHand': 'Float on hand',
        'teller.receiveFloat': 'Receive float',
        'teller.noActiveFloat':
            'No active float. Ask the cashier to issue one.',
        'teller.pendingReceipt':
            'A float is waiting for you. Review the issued note breakdown and receive it with your PIN before serving customers.',
        'teller.pendingReconciliation':
            'Your float is with the cashier for confirmation. The counter reopens once it is closed and a new float is issued.',
        'teller.current': 'Current',
        'teller.today': 'Today',
        'teller.notes': 'Notes',
        'teller.receipt': 'Receipt',
        'teller.return': 'Return',
        'teller.receiveFloatPin': 'Receive float with PIN',
        'teller.handBackCashier': 'Hand back to cashier',
        'teller.confirmHandBackPin': 'Confirm hand back with PIN',
        'teller.returnPinHint':
            'Confirm with your PIN after handing the counted cash to the cashier.',
        'teller.rejectFloatPin': 'Reject with PIN',
        'teller.waitingCashier': 'Waiting for the cashier to confirm',
        'component.account': 'Account',
        'component.chooseAccount': 'Choose an account',
        'component.searchAccount': 'Search account or company…',
        'component.accountBelowRequired':
            'Balance is below the required amount.',
        'component.amount': 'Amount',
        'component.enterAmount': 'Enter Amount',
        'component.notesCounted': 'Notes counted',
        'component.fillLargest': 'Fill from largest',
        'component.counted': 'Counted',
        'component.total': 'Total',
        'component.required': 'Required',
        'component.balanced': 'Balanced',
        'component.overBy': 'Over by',
        'component.shortBy': 'Short by',
        'component.onHand': 'on hand',
        'component.issued': 'issued',
        'component.tapAdd': 'Tap to add one note',
        'component.checkBeforeCommit': 'Check before you commit',
        'component.cashierConfirmHint':
            'Hand the cash to the cashier. This slip completes when they confirm it into the vault.',
        'component.reference': 'Reference',
        'component.type': 'Type',
        'component.time': 'Time',
        'common.balanced': 'Balanced',
        'common.entries': 'entries',
        'common.history': 'History',
        'common.next': 'Next',
        'common.previous': 'Previous',
        'common.records': 'records',
        'common.refresh': 'Refresh',
        'common.reject': 'Reject',
        'common.show': 'Show',
        'component.denomination': 'Denomination',
        'teller.countIncomingFloat': 'Count incoming float',
        'teller.floatTransactions': 'Float transactions',
        'teller.noFloatTransactions': 'No float transactions yet.',
        'teller.pinReject': 'Enter your PIN to reject this float.',
        'teller.rejectFloatTitle': 'Reject float',
        'transaction.accountBalanceNotEnough':
            'The selected account does not have enough balance.',
        'transaction.cashDue': 'Cash due',
        'transaction.cashShortfall': 'Cash shortfall',
        'transaction.cashierHandoffNotReady':
            'Cashier handoff is not ready yet.',
        'transaction.changeDue': 'Change due',
        'transaction.chooseAccountFirst': 'Choose an account first.',
        'transaction.completeRequiredFields':
            'Complete all required fields before continuing.',
        'transaction.countCustomerCash': 'Count customer cash',
        'transaction.customerNameRequired': 'Customer name is required.',
        'transaction.customerPhoneRequired': 'Customer phone is required.',
        'transaction.enterAmountBeforeContinue':
            'Enter an amount before continuing.',
        'transaction.enterCashInAmountFirst': 'Enter the Cash In amount first.',
        'transaction.readyForReview': 'Ready for review',
        'transaction.selectAccountBeforeContinue':
            'Select an account before continuing.',
        'transaction.cashOutDenominationHint':
            'Count the exact notes for the Cash Out settlement.',
    },
    mm: {
        'brand.name': 'ဒေါက်တာဖုန်း',
        'brand.operations': 'ဒေါက်တာဖုန်း လုပ်ငန်းစီမံရေး',
        'language.english': 'English',
        'language.myanmar': 'မြန်မာ',
        'role.admin': 'စနစ်စီမံခန့်ခွဲသူ',
        'role.cashier': 'ငွေတိုက်တာဝန်ခံ',
        'role.teller': 'ငွေကိုင်ဝန်ထမ်း',
        'section.banking': 'ဘဏ်လုပ်ငန်း',
        'section.office': 'ကိုယ်ရေးနှင့်လုံခြုံရေး',
        'section.admin': 'စနစ်စီမံခန့်ခွဲမှု',
        'nav.overview': 'အနှစ်ချုပ်',
        'nav.counter': 'ကောင်တာ',
        'nav.myFloat': 'ကိုယ်ပိုင်ငွေခွဲ',
        'nav.cashIn': 'ငွေသွင်း',
        'nav.cashOut': 'ငွေထုတ်',
        'nav.transfer': 'ငွေလွှဲ',
        'nav.exchange': 'ငွေလဲ',
        'nav.accounts': 'အကောင့်များ',
        'nav.vault': 'အဓိကငွေသေတ္တာ',
        'nav.reconcile': 'စာရင်းညှိခြင်း',
        'nav.reports': 'အစီရင်ခံစာများ',
        'nav.settings': 'ဆက်တင်များ',
        'common.announcement': 'ကြေညာချက်',
        'common.openMenu': 'မီနူးဖွင့်ရန်',
        'common.closeMenu': 'မီနူးပိတ်ရန်',
        'common.desktopNavigation': 'ကွန်ပျူတာမီနူး',
        'common.mobileNavigation': 'ဖုန်းမီနူး',
        'common.language': 'ဘာသာစကား',
        'common.expandMenu': 'မီနူးချဲ့ရန်',
        'common.collapseMenu': 'မီနူးကျဉ်းရန်',
        'common.pendingNotifications': 'အသိပေးချက် စောင့်နေသည်',
        'common.noPendingNotifications': 'စောင့်နေသော အသိပေးချက် မရှိပါ',
        'common.signOut': 'ထွက်မည်',
        'common.secureConnection': 'လုံခြုံစွာ ချိတ်ဆက်ထားပါတယ်',
        'common.home': 'ပင်မစာမျက်နှာ',
        'common.back': 'နောက်သို့',
        'common.cancel': 'မလုပ်တော့ပါ',
        'common.confirm': 'အတည်ပြုမည်',
        'common.clear': 'ဖျက်မည်',
        'common.select': 'ရွေးမည်',
        'common.change': 'ပြောင်းမည်',
        'common.search': 'ရှာမည်',
        'common.noResults': 'ကိုက်ညီသောအချက် မတွေ့ပါ။',
        'common.yes': 'ဟုတ်ကဲ့',
        'common.no': 'မဟုတ်ပါ',
        'common.noAccounts': 'အကောင့်မရှိသေးပါ။',
        'common.noTransactions': 'ယနေ့အတွက် ငွေစာရင်းမရှိသေးပါ။',
        'common.review': 'ပြန်စစ်မည်',
        'common.reviewSlip': 'စာရင်းပြန်စစ်မည်',
        'common.continueReview': 'ပြန်စစ်ရန် ဆက်သွားမည်',
        'common.backToEdit': 'ပြန်ပြင်မည်',
        'common.recording': 'မှတ်တမ်းတင်နေပါတယ်…',
        'common.submitting': 'ပေးပို့နေပါတယ်…',
        'common.verifying': 'စစ်ဆေးနေပါတယ်…',
        'common.authorise': 'ခွင့်ပြုမည်',
        'common.yourPin': 'သင့် PIN',
        'common.pinHint':
            'ဂဏန်း ၄–၈ လုံး။ မှားယွင်းမှု ၃ ကြိမ်ဖြစ်လျှင် လုပ်ဆောင်ချက်ကို ခေတ္တပိတ်ပါမည်။',
        'common.close': 'ပိတ်မည်',
        'status.active': 'ကောင်တာဖွင့်ထားသည်',
        'status.floatToCount': 'ငွေခွဲ ရေတွက်ရန်',
        'status.withCashier': 'Cashier ထံတွင်',
        'status.counterClosed': 'ကောင်တာပိတ်ထားသည်',
        'status.awaitingCashier': 'Cashier အတည်ပြုရန် စောင့်နေသည်',
        'status.completed': 'ပြီးမြောက်သည်',
        'status.cancelled': 'ပယ်ဖျက်ထားသည်',
        'dashboard.title': 'အနှစ်ချုပ်',
        'dashboard.cashFlow': 'ငွေသွင်း နှင့် ငွေထုတ်',
        'dashboard.downloadPdf': 'PDF ဒေါင်းလုပ်',
        'dashboard.oneYear': '၁ နှစ်',
        'dashboard.sixMonths': '၆ လ',
        'dashboard.oneMonth': '၁ လ',
        'dashboard.oneWeek': '၁ ပတ်',
        'dashboard.accounts': 'အကောင့်များ',
        'dashboard.liveBalances': 'လက်ရှိလက်ကျန်',
        'dashboard.totalAccounts': 'အကောင့်',
        'dashboard.totalBalance': 'စုစုပေါင်းလက်ကျန်',
        'dashboard.accountName': 'အကောင့်',
        'dashboard.accountNumber': 'ဖုန်း / အကောင့်နံပါတ်',
        'dashboard.service': 'ဝန်ဆောင်မှု',
        'dashboard.balance': 'လက်ကျန်',
        'dashboard.feeAccount': 'ဝန်ဆောင်ခအကောင့်',
        'dashboard.noAccounts': 'ဒီကုမ္ပဏီအတွက် အကောင့်မရှိသေးပါ။',
        'dashboard.myFloat': 'ကိုယ်ပိုင်ငွေခွဲ',
        'dashboard.activeFloats': 'အသုံးပြုနေသော ငွေခွဲများ',
        'dashboard.goToFloats': 'ငွေခွဲစာရင်းသို့',
        'dashboard.officeWide': 'ရုံးတစ်ခုလုံး',
        'dashboard.holder': 'ကိုင်ဆောင်သူ',
        'dashboard.onHand': 'လက်ထဲရှိငွေ',
        'dashboard.recentHistory': 'လတ်တလောစာရင်း',
        'dashboard.newEntry': 'စာရင်းအသစ်',
        'dashboard.reviewMode': 'ပြန်စစ်ရန်အခြေအနေ',
        'dashboard.configurationMode': 'စီမံခန့်ခွဲရန်အခြေအနေ',
        'dashboard.pendingCashIn': 'စောင့်ဆိုင်းနေသော ငွေသွင်းစာရင်း',
        'dashboard.pendingReviewHint':
            'လက်ခံရငွေ၊ အပ်ငွေနဲ့ အကြွေအမ်းငွေ ကိုက်ညီမှသာ အတည်ပြုပါ။',
        'dashboard.received': 'လက်ခံရငွေ',
        'dashboard.handoff': 'အပ်ငွေ',
        'dashboard.settlementCash': 'အတည်ပြုမည့်ငွေ',
        'dashboard.mainVaultCash': 'ပင်မငွေသေတ္တာငွေ',
        'dashboard.change': 'အကြွေအမ်း',
        'dashboard.action': 'လုပ်ဆောင်ချက်',
        'dashboard.noPending': 'အတည်ပြုရန် စောင့်နေသော ငွေသွင်းစာရင်း မရှိပါ။',
        'dashboard.noFloats': 'အသုံးပြုနေသော ငွေခွဲ မရှိသေးပါ။',
        'dashboard.pending': 'စောင့်ဆိုင်း',
        'dashboard.confirm': 'အတည်ပြုမည်',
        'dashboard.cancel': 'ပယ်ဖျက်မည်',
        'dashboard.unableUpdate': 'ဒီငွေသွင်းစာရင်းကို ပြင်ဆင်၍မရပါ။',
        'dashboard.denominationReview': 'ငွေစက္ကူစစ်ဆေးမှု',
        'dashboard.verifyBeforeConfirm': 'အတည်မပြုမီ ငွေစက္ကူများစစ်ပါ',
        'dashboard.denominationReviewHint':
            'လက်ခံရငွေ၊ Teller အကြွေအမ်းငွေ၊ Cashier အပ်ငွေကို အတည်မပြုမီ စစ်ပါ။',
        'dashboard.expectedHandoff': 'မျှော်မှန်းအပ်ငွေ',
        'dashboard.expectedSettlement': 'မျှော်မှန်းအတည်ပြုငွေ',
        'dashboard.noDenomination': 'ငွေစက္ကူစာရင်း မရှိပါ။',
        'dashboard.denominationBalanced': 'ငွေစက္ကူ ကိုက်ညီပါသည်',
        'dashboard.denominationMismatch': 'ငွေစက္ကူ မကိုက်ညီပါ',
        'dashboard.confirmCashInWithPin': 'Cash In အတည်ပြုမည်',
        'dashboard.confirmCashInWithPinHint':
            'ဒီငွေကို ပင်မငွေသေတ္တာထဲ စာရင်းသွင်းရန် Cashier PIN ထည့်ပါ။',
        'transaction.cashIn': 'ငွေသွင်း',
        'transaction.cashOut': 'ငွေထုတ်',
        'transaction.transfer': 'ငွေလွှဲ',
        'transaction.exchange': 'ငွေလဲ',
        'transaction.enterDetails': 'Cash In စာရင်းသွင်းရန်',
        'transaction.enterCashOutDetails': 'Cash Out စာရင်းသွင်းရန်',
        'transaction.enterTransferDetails': 'ငွေလွှဲစာရင်းသွင်းရန်',
        'transaction.description': 'မှတ်ချက်',
        'transaction.fee': 'ဝန်ဆောင်ခ',
        'transaction.feePaymentMethod': 'ဝန်ဆောင်ခ ပေးချေမည့်နည်း',
        'transaction.feePaymentCash': 'ငွေသားဖြင့်',
        'transaction.feePaymentCashHint':
            'ဝန်ဆောင်ခကို ငွေသားလှုပ်ရှားမှုထဲ ထည့်မည်။',
        'transaction.cashOutFeeCashOutcome':
            'Customer က fee ကို ငွေသားပေးမည်။ Fee notes ကို Teller ငွေခွဲထဲ တိုးမည်။',
        'transaction.cashSettlement': 'ငွေသားရှင်းတမ်း',
        'transaction.cashSettlementHint':
            'Customer ကိုပေးငွေ၊ လက်ခံ fee နှင့် ပြန်အမ်းငွေကို တစ်နေရာတည်းတွင် ရေတွက်ပါ။',
        'transaction.customerPayout': 'Customer ကိုပေးငွေ',
        'transaction.customerPayoutShort': 'ပေးငွေ −',
        'transaction.feeReceived': 'Fee ငွေသားလက်ခံ',
        'transaction.feeReceivedShort': 'Fee +',
        'transaction.changeToCustomer': 'Customer ကိုပြန်အမ်းငွေ',
        'transaction.changeShort': 'ပြန်အမ်း −',
        'transaction.projected': 'ပြီးလျှင်ကျန်',
        'transaction.netTellerCash': 'Teller ငွေသားအသားတင်',
        'transaction.fillPayout': 'ပေးငွေ အလိုအလျောက်ဖြည့်',
        'transaction.fillChange': 'ပြန်အမ်းငွေ အလိုအလျောက်ဖြည့်',
        'transaction.fillExactDue': 'ကျသင့်ငွေအတိအကျ ဖြည့်',
        'transaction.receivedShort': 'လက်ခံ +',
        'transaction.amountDue': 'ကျသင့်ငွေ',
        'transaction.customerPaid': 'Customer ပေးငွေ',
        'transaction.netCashReceived': 'အသားတင်လက်ခံငွေ',
        'transaction.cashInCashierSettlementHint':
            'Customer ပေးသော ငွေသားအားလုံးကို တစ်ပေါင်းတည်းရေတွက်ပါ။ ပိုပေးထားပါက ပြန်အမ်းမည့် ငွေစက္ကူများကိုသာ ရွေးပါ။',
        'transaction.customer': 'ဖောက်သည်',
        'transaction.cashInCashierCount': 'Cash In · Cashier ငွေရေတွက်မှု',
        'transaction.cashInPlusCashFee': 'Cash In + ငွေသားဝန်ဆောင်ခ',
        'transaction.cashInFeePaidByAccount':
            'Cash In သာ · ဝန်ဆောင်ခကို အကောင့်မှပေးချေသည်',
        'transaction.closeCashInReview': 'Cash In စစ်ဆေးမှု ပိတ်မည်',
        'transaction.receivedMinusChangeMustEqual':
            'လက်ခံငွေ − ပြန်အမ်းငွေ သည် ကျသင့်ငွေနှင့် တူရမည်',
        'transaction.rejectCashIn': 'Cash In ပယ်ချမည်',
        'transaction.confirmWithPin': 'PIN ဖြင့် အတည်ပြုမည်',
        'transaction.confirmPendingCashIn': 'Cash In အတည်ပြုမည်',
        'transaction.confirmCashInPinHint':
            'ရေတွက်ထားသောငွေကို Main Vault သို့ စာရင်းတင်ရန် Cashier PIN ထည့်ပါ။',
        'transaction.rejectCashInPinHint':
            'စောင့်ဆိုင်းနေသော Cash In ကို ပြန်လှန်ပယ်ချရန် Cashier PIN ထည့်ပါ။',
        'transaction.insufficientChangeNotes':
            'ပြန်အမ်းရန် ငွေစက္ကူမလုံလောက်ပါ',
        'transaction.cashierCountsCash': 'Cashier က ငွေသားကို ရေတွက်မည်',
        'transaction.cashierCountsCashHint':
            'Teller သည် Cash In စာရင်းကိုသာ သွင်းရမည်။ Cashier က လက်ခံရငွေနှင့် ပြန်အမ်းငွေကို ရေတွက်ပြီးမှ အတည်ပြုမည်။',
        'transaction.physicalCashCount': 'ငွေသားရေတွက်မှု',
        'transaction.pendingCashierCount':
            'Cashier ရေတွက်ရန် စောင့်ဆိုင်းနေသည်',
        'transaction.cashSettlementMatched': 'ငွေသားရှင်းတမ်း ကိုက်ညီပါသည်',
        'transaction.cashFeeReceivedMinimumHint':
            'Customer ဆီမှ လက်ခံသော fee ငွေသားကို သတ်မှတ် fee ထက် မနည်းအောင် ရေတွက်ပါ။',
        'transaction.cashOutChangeHint':
            'Customer ကိုပြန်အမ်းရမည့် ငွေပမာဏအတိအကျကို ရေတွက်ပါ။',
        'transaction.projectedStockError':
            'ငွေစက္ကူအမျိုးအစားတစ်ခု၏ လက်ကျန်သည် သုညအောက်ကျနေသည်။ ပေးငွေ သို့မဟုတ် ပြန်အမ်းငွေကို ပြင်ပါ။',
        'transaction.feePaymentAccount': 'အကောင့်ဖြင့်',
        'transaction.feePaymentAccountHint':
            'မူလအကောင့်မှ နုတ်ပြီး ဝန်ဆောင်ခအကောင့်ထဲ တိုးမည်။',
        'transaction.feePaymentAccountIncludedHint':
            'Customer က Amount နှင့် Fee ကို System လက်ခံအကောင့်ထဲ အတူပေးမည်။',
        'transaction.cashOutAccountFeeHint':
            'Fee ကို ရွေးထားတဲ့ ငွေဝင်မည့်အကောင့်ထဲပဲ ထည့်မည်။ Fee account ထပ်ရွေးရန် မလိုပါ။',
        'transaction.cashOutAccountFeeDestination': 'Fee ဝင်မည့်အကောင့်: ',
        'transaction.feeAccount': 'ဝန်ဆောင်ခထည့်မည့်အကောင့်',
        'transaction.chooseFeeAccount': 'ဝန်ဆောင်ခထည့်မည့်အကောင့် ရွေးပါ',
        'transaction.noFeeAccounts':
            'အသုံးပြုနိုင်သော ဝန်ဆောင်ခအကောင့် မရှိသေးပါ။',
        'transaction.feeAccountRequired':
            'ဝန်ဆောင်ခ လက်ခံမည့်အကောင့်ကို ရွေးပါ။',
        'transaction.feeAmount': 'ဝန်ဆောင်ခ',
        'transaction.commissionTier': 'သတ်မှတ်ထားသော ဝန်ဆောင်ခအဆင့်',
        'transaction.transferFeeTier': 'ကုမ္ပဏီလမ်းကြောင်း ဝန်ဆောင်ခအဆင့်',
        'transaction.agentCommission': 'အေးဂျင့် ကော်မရှင်',
        'transaction.receiveCommission': 'လက်ခံအကောင့် ကော်မရှင်',
        'transaction.payoutCommission': 'ပေးပို့အကောင့် ကော်မရှင်',
        'transaction.customerSends': 'Customer ပေးမည်',
        'transaction.systemReceives': 'System လက်ခံမည်',
        'transaction.systemSends': 'System ပေးပို့မည်',
        'transaction.customerReceives': 'Customer လက်ခံမည်',
        'transaction.receiveLeg': 'လက်ခံခြမ်း',
        'transaction.payoutLeg': 'ပေးပို့ခြမ်း',
        'transaction.payBank': 'Pay / Bank',
        'transaction.noSystemAccount':
            'ဒီ Company အတွက် အသုံးပြုနိုင်သော System account မရှိသေးပါ။',
        'transaction.cashReceived': 'လက်ခံရငွေ',
        'transaction.cashReceivedCustomer': 'ဖောက်သည်ထံမှ လက်ခံရရှိသောငွေ',
        'transaction.cashInCountPrerequisite':
            'ငွေစက္ကူရေတွက်ရန် အကောင့်ကိုရွေးပြီး Cash In ပမာဏကို အရင်ထည့်ပါ။',
        'transaction.cashInDenominationHint':
            'ဖောက်သည်ထံမှ လက်ခံရငွေကို ရေတွက်ပါ။ အကြွေလိုပါက မိမိ Teller ငွေခွဲမှ အကြွေကို ရေတွက်ပါ။ Cashier ထံ အပ်မည့်ငွေကို စနစ်က အလိုအလျောက်တွက်ချက်ပါမယ်။',
        'transaction.cashInDescription':
            'ဖောက်သည်ထံမှ ငွေသားလက်ခံပြီး အကောင့်ကို နုတ်ကာ Cashier ထံ အတည်ပြုရန် စာရင်းပေးပါ။',
        'transaction.afterCashierConfirmation': 'Cashier အတည်ပြုပြီးနောက်',
        'transaction.cashOutDescription':
            'မိမိ Teller ငွေခွဲမှ ငွေထုတ်ပေးပြီး အကောင့်ထဲ ငွေဝင်ကြောင်း စာရင်းသွင်းပါ။',
        'transaction.transferDescription':
            'အကောင့်များအကြား ငွေလွှဲပြီး မိမိ Teller ငွေခွဲမှ ငွေစက္ကူများကို စီမံပါ။',
        'transaction.exchangeDescription':
            'Server မှ လက်ရှိဝယ်ဈေး၊ ရောင်းဈေးများကို အသုံးပြုပြီး ငွေလဲစာရင်းသွင်းပါ။',
        'transaction.customerName': 'ဖောက်သည်အမည်',
        'transaction.customerPhone': 'ဖောက်သည်ဖုန်း',
        'transaction.cashShort': 'လက်ခံရငွေသည် ငွေသွင်းပမာဏထက် နည်းနေပါတယ်။',
        'transaction.changeNotice':
            'အကြွေအမ်းငွေကို မိမိ Teller ငွေခွဲမှ ပေးရပါမယ်။',
        'transaction.floatAfterChange': 'အကြွေလဲပြီးနောက် Teller ငွေခွဲ',
        'transaction.cashInConsequence':
            'အကောင့်လက်ကျန်ကို ချက်ချင်းနုတ်ပါမယ်။ Cashier က ငွေအပ်လက်ခံကြောင်း အတည်ပြုပြီးမှ အဓိကငွေသေတ္တာထဲ ငွေဝင်ပါမယ်။',
        'transaction.cashOutConsequence':
            'သတ်မှတ်ထားသော ငွေစက္ကူများကို မိမိ Teller ငွေခွဲမှ နုတ်ပြီး အကောင့်ထဲ ငွေဝင်ပါမယ်။',
        'transaction.transferConsequence':
            'မူလအကောင့်ကို နုတ်ပြီး သွားမည့်အကောင့်ထဲ ငွေဝင်ပါမယ်။ သတ်မှတ်ထားသော ငွေစက္ကူများကို မိမိ Teller ငွေခွဲမှ နုတ်ပါမယ်။',
        'transaction.exchangeConsequence':
            'ငွေလဲမည့်အကောင့်ထဲ ငွေဝင်ပြီး သတ်မှတ်ထားသော ငွေစက္ကူများကို မိမိ Teller ငွေခွဲမှ နုတ်ပါမယ်။',
        'transaction.completedHint':
            'အသေးစိတ်စစ်ဆေးပြီးနောက် ဒီရည်ညွှန်းနံပါတ်ကို ဖောက်သည်အား ပြပါ။',
        'transaction.slip': 'လက်ခံစာ',
        'transaction.rate': 'ဈေးနှုန်း',
        'transaction.floatCashPaid': 'Teller ငွေခွဲမှ ပေးထားသောငွေ',
        'transaction.floatShort':
            'မိမိ Teller ငွေခွဲတွင် ငွေမလုံလောက်ပါ။ ပမာဏလျှော့ပါ သို့မဟုတ် Cashier ထံမှ ငွေဖြည့်တောင်းပါ။',
        'transaction.cashHandedCashier': 'Cashier ထံ အပ်မည့်ငွေ',
        'transaction.changeMyVault': 'မိမိ Teller ငွေခွဲမှ အကြွေလဲပေးမည့်ငွေ',
        'transaction.notesMainVault': 'Cashier အဓိကငွေသေတ္တာမှ ငွေ',
        'transaction.notesMyVault': 'ကိုယ်ပိုင်ငွေခွဲမှ ငွေ',
        'transaction.accountDebit': 'ငွေနုတ်မည့် KPay အကောင့်',
        'transaction.accountCredit': 'ငွေထည့်မည့် KPay အကောင့်',
        'transaction.cashOutAccountCredit': 'ငွေဝင်မည့်အကောင့်',
        'transaction.exchangeAccount': 'ငွေလဲမည့်အကောင့်',
        'transaction.exchangePaymentMethod': 'ငွေလဲလှယ်မှု ပေးချေမည့်နည်းလမ်း',
        'transaction.sourceAccount': 'ငွေထွက်မည့်အကောင့်',
        'transaction.sourceProvider': 'မူလ Pay/Bank',
        'transaction.sourceCompany': 'မူလ Company',
        'transaction.transferCustomerInfo': 'Customer information',
        'transaction.transferCustomerInfoHint':
            'Customer / beneficiary name နဲ့ account number ကို manual ရိုက်ထည့်ပါ။ System account တွေကို အောက်မှာရွေးပါ။',
        'transaction.customerPayBank': 'Customer Pay/Bank',
        'transaction.customerSourceCompany': 'Customer source company',
        'transaction.sourceBeneficiaryName': 'Customer / beneficiary name',
        'transaction.sourceManualHint':
            'Customer ဘက် Company, Name, Account ကို manual ဖြည့်ပါ။',
        'transaction.sourceAccountNumber': 'မူလအကောင့်နံပါတ်',
        'transaction.customerSourceAccountNumber':
            'Customer source account number',
        'transaction.destinationCompany': 'သွားမည့် Company',
        'transaction.destinationCompanyHint':
            'Company ကို အရင်ရွေးပါ။ အဲဒီ company နဲ့ဆိုင်တဲ့ accounts တွေပဲ အောက်မှာပြပါမယ်။',
        'transaction.destinationAccount': 'ငွေဝင်မည့်အကောင့်',
        'transaction.destinationAccountFilteredHint':
            'Destination account list ကို ရွေးထားတဲ့ company နဲ့ filter လုပ်ထားပါတယ်။',
        'transaction.systemReceiveCompany': 'System ငွေလက်ခံမည့် Company',
        'transaction.systemReceiveCompanyHint':
            'Customer က ဒီ system Pay/Bank company ထဲကို ငွေပေးမည်။',
        'transaction.systemReceiveAccount': 'System ငွေလက်ခံမည့်အကောင့်',
        'transaction.systemPayoutCompany': 'System ငွေပို့မည့် Company',
        'transaction.systemPayoutCompanyHint':
            'System က ဒီ company အကောင့်မှ Customer destination သို့ ငွေပို့မည်။',
        'transaction.systemPayoutAccount': 'System ငွေပို့မည့်အကောင့်',
        'transaction.customerDestinationAccount':
            'Customer destination account',
        'transaction.destinationBeneficiaryName': 'Customer / beneficiary name',
        'transaction.customerDestinationHint':
            'Customer destination account number ကို manual ရိုက်ထည့်ပါ။ Company က ရွေးထားတဲ့ system payout company ဖြစ်ပါမယ်။',
        'transaction.destinationAccountNumber': 'Destination account number',
        'transaction.customerDestinationAccountNumber':
            'Customer destination account number',
        'transaction.cashInAmount': 'Cash In ပမာဏ',
        'transaction.amountReadingHint': 'ငွေပမာဏ ဖတ်ရန်',
        'transaction.cashOutAmount': 'ငွေထုတ်မည့်ပမာဏ',
        'transaction.cashToExchange': 'ငွေလဲမည့်ပမာဏ',
        'transaction.transferAmount': 'လွှဲမည့်ပမာဏ',
        'transaction.reviewCashIn': 'Cash In စာရင်း ပြန်စစ်ရန်',
        'transaction.reviewCashOut': 'ငွေထုတ်စာရင်း ပြန်စစ်ရန်',
        'transaction.reviewHint': 'အတည်မပြုမီ စာရင်းတစ်ကြောင်းချင်း စစ်ပေးပါ။',
        'transaction.confirmCashIn': 'Cash In စာရင်းတင်မည်',
        'transaction.confirmCashOut': 'ငွေထုတ်အတည်ပြုမည်',
        'transaction.confirmTransfer': 'ငွေလွှဲအတည်ပြုမည်',
        'transaction.confirmExchange': 'ငွေလဲအတည်ပြုမည်',
        'transaction.newCashIn': 'ငွေသွင်းအသစ်',
        'transaction.newCashOut': 'ငွေထုတ်အသစ်',
        'transaction.cashInSubmitted': 'Cash In စာရင်း တင်ပြီးပါပြီ',
        'transaction.cashOutSuccessful': 'ငွေထုတ်ပြီးပါပြီ',
        'transaction.awaitingCashier': 'Cashier အတည်ပြုရန် စောင့်နေပါတယ်',
        'transaction.cashierLocked':
            'Cashier က စာရင်းပြန်စစ်ပြီး အတည်ပြုနိုင်ပါတယ်။ ဒီနေရာကနေ စာရင်းအသစ် မသွင်းနိုင်ပါ။',
        'transaction.floatLocked':
            'ကိုယ်ပိုင်ငွေခွဲ မဖွင့်ရသေးပါ။ စာရင်းမသွင်းမီ Cashier ထံမှ ငွေခွဲလက်ခံပါ။',
        'transaction.goToFloats': 'ငွေခွဲစာရင်းသို့',
        'transaction.accountDeducted': 'နုတ်မည့်အကောင့်',
        'transaction.accountCredited': 'ငွေဝင်မည့်အကောင့်',
        'transaction.amount': 'ပမာဏ',
        'transaction.status': 'အခြေအနေ',
        'transaction.kpayBalanceDecreased': 'KPay လက်ကျန် လျော့မည်',
        'transaction.kpayBalanceIncreased': 'KPay လက်ကျန် တိုးမည်',
        'transaction.accountBalanceIncreased': 'အကောင့်လက်ကျန် တိုးမည်',
        'transaction.mainVaultIncrease': 'Cashier Main Vault ထဲ ဝင်မည့်ငွေ',
        'transaction.tellerVaultNetChange':
            'Teller ငွေခွဲ စုစုပေါင်းပြောင်းလဲမှု',
        'transaction.tellerDenominationChange':
            'Teller ငွေခွဲ အမျိုးအစားပြောင်းလဲမှု',
        'transaction.cashPaidCustomer': 'ဖောက်သည်ကို ပေးမည့်ငွေ',
        'transaction.countedMovement': 'ရေတွက်ထားသော ငွေရွှေ့ပြောင်းမှု',
        'transaction.floatAfterPayout': 'ငွေထုတ်ပြီးနောက် ငွေခွဲလက်ကျန်',
        'transaction.floatAfterTransfer': 'ငွေလွှဲပြီးနောက် ငွေခွဲလက်ကျန်',
        'transaction.floatAfterExchange': 'ငွေလဲပြီးနောက် ငွေခွဲလက်ကျန်',
        'transaction.direction': 'လဲလှယ်မည့်ဦးတည်ချက်',
        'transaction.sellRate': 'ရောင်းဈေး',
        'transaction.buyRate': 'ဝယ်ဈေး',
        'transaction.mmkToThb': 'MMK မှ THB',
        'transaction.thbToMmk': 'THB မှ MMK',
        'teller.counter': 'ကိုယ်ပိုင်ကောင်တာ',
        'teller.float': 'ကိုယ်ပိုင်ငွေခွဲ',
        'teller.floatOnHand': 'လက်ထဲရှိ ငွေခွဲ',
        'teller.receiveFloat': 'ငွေခွဲလက်ခံမည်',
        'teller.noActiveFloat':
            'အသုံးပြုနိုင်သော ငွေခွဲမရှိပါ။ Cashier ထံမှ ငွေခွဲတောင်းပါ။',
        'teller.pendingReceipt':
            'သင့်အတွက် ငွေခွဲစောင့်နေပါတယ်။ Cashier ထည့်ထားသော ငွေစက္ကူစာရင်းကို လက်တွေ့ငွေနှင့်စစ်ပြီး PIN ဖြင့် လက်ခံပါ။',
        'teller.pendingReconciliation':
            'သင့်ငွေခွဲကို Cashier ထံ စာရင်းစစ်ရန် အပ်ထားပါတယ်။ စာရင်းပိတ်ပြီး ငွေခွဲအသစ်ထုတ်ပေးမှ ကောင်တာပြန်ဖွင့်ပါမယ်။',
        'teller.issued': 'ထုတ်ပေးထားငွေ',
        'teller.till': 'ကိုယ်ပိုင်ကောင်တာ',
        'teller.onHandNow': 'လက်ရှိလက်ထဲရှိငွေ',
        'teller.paidOutToday': 'ယနေ့ပေးပြီးငွေ',
        'teller.transactionsEntered': 'စာရင်းသွင်းထားသည်',
        'teller.recentEntries': 'လတ်တလောစာရင်းများ',
        'teller.floatNumber': 'ငွေခွဲ',
        'teller.cashInNote':
            'ဖောက်သည်က ငွေပေးပြီး အကောင့်လက်ကျန်ကို နုတ်ပါမယ်။',
        'teller.cashOutNote':
            'မိမိ Teller ငွေခွဲမှ ငွေပေးပြီး အကောင့်ထဲ ငွေဝင်ပါမယ်။',
        'teller.transferNote': 'အကောင့်များအကြား ငွေလွှဲပါမယ်။',
        'teller.exchangeNote': 'ယနေ့ MMK / THB ဈေးနှုန်းဖြင့် ငွေလဲပါမယ်။',
        'teller.ref': 'ရည်ညွှန်း',
        'teller.type': 'အမျိုးအစား',
        'teller.amount': 'ပမာဏ',
        'teller.fee': 'ဝန်ဆောင်ခ',
        'teller.status': 'အခြေအနေ',
        'teller.noRecentEntries':
            'စာရင်းမရှိသေးပါ။ ယနေ့ပထမဆုံးစာရင်းကို ဒီနေရာမှာ ပြပါမယ်။',
        'teller.floatDescription':
            'Cashier ထံ ပြန်အပ်ပြီး စာရင်းပိတ်သည်အထိ သင့်တာဝန်ယူထားရမည့် ငွေသားဖြစ်ပါတယ်။',
        'teller.receiveFloatPage': 'ငွေခွဲလက်ခံရန်',
        'teller.returnCashPage': 'ငွေပြန်အပ်ရန်',
        'teller.floatHistoryPage': 'ငွေခွဲမှတ်တမ်း',
        'teller.receiveFloatDescription':
            'Cashier ထည့်ထားသော ငွေစက္ကူစာရင်းကို စစ်ပြီး PIN ဖြင့် လက်ခံ သို့မဟုတ် Reject လုပ်ပါ။',
        'teller.returnCashDescription':
            'Cashier ထံ ပြန်အပ်မည့် ငွေစက္ကူအရေအတွက်ကို တစ်ကြိမ်တည်းရေတွက်ထည့်ပြီး PIN ဖြင့် အတည်ပြုပါ။',
        'teller.floatHistoryDescription':
            'မိမိ၏ ငွေခွဲ session များနှင့် ထပ်မံထုတ်ပေးထားသော ငွေခွဲမှတ်တမ်းများကို ကြည့်ပါ။',
        'teller.cashierIssuedNotes': 'Cashier ထုတ်ပေးသော ငွေစက္ကူများ',
        'teller.pendingReceiptTitle': 'လက်ခံရန် ငွေခွဲစောင့်နေသည်',
        'teller.openReceiveFloat': 'ငွေခွဲလက်ခံရန် သွားမည်',
        'teller.pendingAdditionalFloatNotice':
            'ထပ်မံထုတ်ပေးထားသော ငွေခွဲကို စစ်ဆေးလက်ခံရန် စောင့်နေသည်။',
        'teller.reviewNow': 'ယခုစစ်မည်',
        'teller.onHandBreakdown': 'လက်ရှိ ငွေစက္ကူစာရင်း',
        'teller.myFloatReadOnlyHint':
            'ဒီစာမျက်နှာမှာ လက်ကျန်ကိုသာကြည့်နိုင်ပါတယ်။ Cashier ထံ ငွေပြန်အပ်ရန် Return Cash ကိုသုံးပါ။',
        'teller.noPendingFloat': 'လက်ခံရန် ငွေခွဲမရှိပါ',
        'teller.noPendingFloatDescription':
            'Cashier က ပထမဆုံး သို့မဟုတ် ထပ်မံ ငွေခွဲထုတ်ပေးပါက ဒီနေရာမှာ PIN ဖြင့် စစ်ဆေးလက်ခံနိုင်ပါမယ်။',
        'teller.additionalFloatIssues': 'ထပ်မံထုတ်ပေးသော ငွေခွဲများ',
        'teller.additionalReceiveHint':
            'Cashier ထည့်ထားသော ငွေစက္ကူစာရင်းကိုသာ စစ်ပါ။ အရွက်အရေအတွက်ကို ပြန်ရိုက်ရန်မလိုပါ။',
        'teller.reviewReceive': 'စစ်ပြီး လက်ခံမည်',
        'teller.receiveBeforeReturn':
            'ငွေပြန်အပ်မီ စောင့်နေသော ထပ်မံငွေခွဲအားလုံးကို လက်ခံ သို့မဟုတ် Reject လုပ်ပါ။',
        'teller.receiveBeforeReturnTitle': 'ငွေခွဲကို အရင်လက်ခံပါ',
        'teller.additionalIssueHistory': 'ထပ်မံငွေခွဲ မှတ်တမ်း',
        'teller.additionalIssueHistoryHint':
            'ထပ်မံထုတ်ပေးခဲ့သော ငွေခွဲများနှင့် နောက်ဆုံးအခြေအနေကို ဖတ်ရှုရန်သာဖြစ်သည်။',
        'teller.floatHistoryReadOnly':
            'ငွေခွဲမှတ်တမ်းများကို ဖတ်ရှုရန်သာဖြစ်သည်။ လက်ခံခြင်းနှင့် ပြန်အပ်ခြင်းကို သီးခြား menu များမှ လုပ်ပါ။',
        'teller.noFloat': 'ငွေခွဲ မထုတ်ပေးရသေးပါ',
        'teller.askCashier':
            'Cashier ထံမှ ငွေခွဲထုတ်ခိုင်းပါ။ Cashier ထည့်ထားသော ငွေစက္ကူအမျိုးအစားနှင့် အရေအတွက်ကို စစ်ပြီး PIN ဖြင့်လက်ခံပါက ကောင်တာဖွင့်ပါမယ်။',
        'teller.countIssued':
            'Cashier က ထုတ်ပေးထားသော ငွေစက္ကူအမျိုးအစားနှင့် အရေအတွက်ကို ထည့်ထားပါတယ်။ လက်တွေ့ရရှိသောငွေနှင့် ကိုက်ညီကြောင်း စစ်ပါ။',
        'teller.countMatch':
            'လက်တွေ့ရရှိသော ငွေစက္ကူများသည် ပြထားသော အမျိုးအစားနှင့် အရေအတွက်အတိုင်း ကိုက်ညီကြောင်း စစ်ပါ။ မကိုက်ပါက လက်မခံဘဲ Reject လုပ်ပါ။',
        'teller.systemOnHand': 'စနစ်အရ လက်ကျန်',
        'teller.youCounted': 'သင်ရေတွက်ထားသည်',
        'teller.returnCloses':
            'ပြန်အပ်ပြီးပါက ငွေခွဲအသစ် မထုတ်ပေးမချင်း ကောင်တာပိတ်ထားပါမယ်။',
        'teller.confirmCount': 'ဝင်လာသော ငွေခွဲကို အတည်ပြုပါ',
        'teller.confirmReturn': 'ပြန်အပ်မည့်ငွေကို အတည်ပြုပါ',
        'teller.pinCount':
            'ငွေစက္ကူများ ကိုက်ညီကြောင်း သင့် PIN ဖြင့် မှတ်တမ်းတင်ပါမယ်။',
        'teller.pinReturn':
            'ပြန်အပ်သည့်စာရင်းကို သင့် PIN ဖြင့် မှတ်တမ်းတင်ပါမယ်။',
        'teller.current': 'လက်ရှိ',
        'teller.today': 'ယနေ့',
        'teller.notes': 'ငွေစက္ကူများ',
        'teller.receipt': 'လက်ခံစာ',
        'teller.return': 'ပြန်အပ်ရန်',
        'teller.receiveFloatPin': 'PIN ဖြင့် ငွေခွဲလက်ခံမည်',
        'teller.handBackCashier': 'Cashier ထံ ပြန်အပ်မည်',
        'teller.confirmHandBackPin': 'PIN ဖြင့် ငွေပြန်အပ်မည်',
        'teller.returnPinHint':
            'ရေတွက်ထားသောငွေကို Cashier ထံ လက်တွေ့အပ်ပြီးနောက် PIN ဖြင့် အတည်ပြုပါ။',
        'teller.rejectFloatPin': 'PIN ဖြင့် Reject လုပ်မည်',
        'teller.waitingCashier': 'Cashier အတည်ပြုရန် စောင့်နေပါတယ်',
        'common.choose': 'ရွေးပါ',
        'transaction.company': 'ကုမ္ပဏီ',
        'transaction.accounts': 'အကောင့်',
        'transaction.companies': 'ကုမ္ပဏီ',
        'transaction.chooseCompanyFirst': 'ဝန်ဆောင်မှုကုမ္ပဏီကို အရင်ရွေးပါ။',
        'transaction.cashOutCreditCompany': 'ငွေဝင်မည့် Company',
        'transaction.cashOutCreditCompanyHint':
            'Company ကို အရင်ရွေးပြီး Cash Out credit ဝင်မည့်အကောင့်ကို ရွေးပါ။',
        'transaction.cashOutFilteredAccountHint':
            'ရွေးထားတဲ့ company နဲ့ဆိုင်တဲ့ accounts တွေပဲ ပြပါမယ်။',
        'transaction.screenshot': 'Screenshot',
        'transaction.attachScreenshot': 'Screenshot တွဲရန်',
        'transaction.screenshotHint': 'PNG, JPG, BMP သို့မဟုတ် GIF 4 MB အထိ။',
        'component.account': 'အကောင့်',
        'component.chooseAccount': 'အကောင့်ရွေးပါ',
        'component.searchAccount': 'အကောင့် သို့မဟုတ် ကုမ္ပဏီ ရှာပါ…',
        'component.accountBelowRequired':
            'အကောင့်လက်ကျန်က လိုအပ်သောပမာဏထက် နည်းနေပါတယ်။',
        'component.amount': 'ပမာဏ',
        'component.enterAmount': 'ပမာဏထည့်ပါ',
        'component.notesCounted': 'ရေတွက်ထားသော ငွေစက္ကူ',
        'component.fillLargest': 'တန်ဖိုးကြီးမှ ဖြည့်မည်',
        'component.counted': 'ရေတွက်ပြီး',
        'component.total': 'စုစုပေါင်း',
        'component.required': 'လိုအပ်သည်',
        'component.balanced': 'ကိုက်ညီပါတယ်',
        'component.overBy': 'ပိုနေသည်',
        'component.shortBy': 'လိုနေသည်',
        'component.onHand': 'လက်ထဲရှိ',
        'component.issued': 'ထုတ်ပေးထား',
        'component.tapAdd': 'ငွေစက္ကူတစ်ရွက် ထပ်ထည့်ရန် နှိပ်ပါ',
        'component.checkBeforeCommit': 'အတည်မပြုမီ စစ်ဆေးပါ',
        'component.cashierConfirmHint':
            'ငွေကို Cashier ထံ အပ်ပါ။ Cashier က အတည်ပြုပြီးမှ ငွေသေတ္တာထဲ စာရင်းဝင်ပါမယ်။',
        'component.reference': 'ရည်ညွှန်းနံပါတ်',
        'component.type': 'အမျိုးအစား',
        'component.time': 'အချိန်',
        'common.balanced': 'ကိုက်ညီပါသည်',
        'common.entries': 'စာရင်း',
        'common.history': 'မှတ်တမ်း',
        'common.next': 'နောက်တစ်မျက်နှာ',
        'common.previous': 'ရှေ့တစ်မျက်နှာ',
        'common.records': 'မှတ်တမ်း',
        'common.refresh': 'ပြန်တင်မည်',
        'common.reject': 'ပယ်ချမည်',
        'common.show': 'ပြမည်',
        'component.denomination': 'ငွေစက္ကူအမျိုးအစား',
        'teller.countIncomingFloat': 'ဝင်လာသော ငွေခွဲ ရေတွက်ရန်',
        'teller.floatTransactions': 'ငွေခွဲမှတ်တမ်းများ',
        'teller.noFloatTransactions': 'ငွေခွဲမှတ်တမ်း မရှိသေးပါ။',
        'teller.pinReject': 'ဒီငွေခွဲကို ပယ်ချရန် သင့် PIN ထည့်ပါ။',
        'teller.rejectFloatTitle': 'ငွေခွဲ ပယ်ချမည်',
        'transaction.accountBalanceNotEnough':
            'ရွေးထားသောအကောင့်တွင် လက်ကျန်မလုံလောက်ပါ။',
        'transaction.cashDue': 'ပေးရန်ငွေသား',
        'transaction.cashFeeReceivedHint':
            'ဖောက်သည်ထံမှ လက်ခံရရှိသော ဝန်ဆောင်ခငွေသားကို ရေတွက်ပါ။',
        'transaction.cashFeeReceivedNotes':
            'ဝန်ဆောင်ခအဖြစ် လက်ခံသော ငွေစက္ကူများ',
        'transaction.cashOutDenominationHint':
            'ငွေထုတ်ရှင်းတမ်းအတွက် ငွေစက္ကူများကို အတိအကျ ရေတွက်ပါ။',
        'transaction.cashShortfall': 'ငွေသားလိုငွေ',
        'transaction.cashierHandoffNotReady':
            'Cashier ထံ အပ်ငွေ အဆင်သင့်မဖြစ်သေးပါ။',
        'transaction.changeDue': 'ပြန်အမ်းရမည့်ငွေ',
        'transaction.chooseAccountFirst': 'အကောင့်ကို အရင်ရွေးပါ။',
        'transaction.completeRequiredFields':
            'ဆက်မလုပ်မီ လိုအပ်သောအချက်များကို ပြည့်စုံအောင်ဖြည့်ပါ။',
        'transaction.countCustomerCash': 'ဖောက်သည်ငွေသား ရေတွက်ရန်',
        'transaction.customerNameRequired': 'ဖောက်သည်အမည် လိုအပ်ပါသည်။',
        'transaction.customerPhoneRequired': 'ဖောက်သည်ဖုန်းနံပါတ် လိုအပ်ပါသည်။',
        'transaction.enterAmountBeforeContinue': 'ဆက်မလုပ်မီ ပမာဏထည့်ပါ။',
        'transaction.enterCashInAmountFirst': 'Cash In ပမာဏကို အရင်ထည့်ပါ။',
        'transaction.readyForReview': 'ပြန်စစ်ရန် အဆင်သင့်',
        'transaction.selectAccountBeforeContinue': 'ဆက်မလုပ်မီ အကောင့်ရွေးပါ။',
    },
};

function initialLocale(): Locale {
    if (typeof window === 'undefined') {
        return 'mm';
    }

    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored === 'en' || stored === 'mm') return stored;

    const cookie = document.cookie.match(
        /(?:^|; )ngwe_lwe_locale=(en|mm)(?:;|$)/,
    )?.[1];
    if (cookie === 'en' || cookie === 'mm') return cookie;

    return 'mm';
}

const locale = ref<Locale>(initialLocale());

export function useLocale() {
    function setLocale(next: Locale): void {
        locale.value = next;

        if (typeof window !== 'undefined') {
            window.localStorage.setItem(STORAGE_KEY, next);
            document.cookie = `ngwe_lwe_locale=${next}; Path=/; Max-Age=31536000; SameSite=Lax`;
            document.documentElement.lang = next === 'mm' ? 'my' : 'en';
        }
    }

    function t(key: string, fallback?: string): string {
        return repairMojibake(
            messages[locale.value][key] ?? messages.en[key] ?? fallback ?? key,
        );
    }

    return { lang: locale, setLang: setLocale, t };
}
