import { watch } from 'vue';
import { useLocale } from '@/lib/i18n';

/**
 * Compatibility catalogue for legacy screens that still contain literal English UI copy.
 * New screens should use keyed t(...) messages directly. This layer keeps existing
 * Admin/Cashier/Teller screens bilingual without changing their layout or business logic.
 */
const mm: Record<string, string> = {
    'Account balance adjusted.': 'အကောင့်လက်ကျန် ပြင်ဆင်ပြီးပါပြီ။',
    'Account deleted.': 'အကောင့် ဖျက်ပြီးပါပြီ။',
    'Account saved.': 'အကောင့် သိမ်းဆည်းပြီးပါပြီ။',
    'Account status updated.': 'အကောင့်အခြေအနေ ပြင်ဆင်ပြီးပါပြီ။',
    'Activity Logs': 'လုပ်ဆောင်မှုမှတ်တမ်းများ',
    'Activity logs refreshed.': 'လုပ်ဆောင်မှုမှတ်တမ်းများ ပြန်တင်ပြီးပါပြီ။',
    'Additional float issued. Teller must review the note breakdown and receive it with PIN before the balance increases.':
        'ထပ်မံငွေခွဲ ထုတ်ပေးပြီးပါပြီ။ လက်ကျန်မတိုးမီ Teller က ငွေစက္ကူစာရင်းကို စစ်ပြီး PIN ဖြင့် လက်ခံရပါမည်။',
    'Agent commissions': 'အေးဂျင့်ကော်မရှင်များ',
    'Backup created.': 'Backup ဖန်တီးပြီးပါပြီ။',
    'Broadcast test sent.': 'Broadcast စမ်းသပ်ပို့ပြီးပါပြီ။',
    'Cancelled by cashier during review.': 'Cashier စစ်ဆေးနေစဉ် ပယ်ဖျက်ထားသည်။',
    'Cash In confirmed and posted to the main vault.':
        'ငွေသွင်းကို အတည်ပြုပြီး Main Vault ထဲ စာရင်းသွင်းပြီးပါပြီ။',
    'Cash deposited into Cashier vault.':
        'Cashier ငွေတိုက်ထဲ ငွေသွင်းပြီးပါပြီ။',
    'Cash withdrawn from Cashier vault.':
        'Cashier ငွေတိုက်မှ ငွေထုတ်ပြီးပါပြီ။',
    'Cashier PIN updated successfully.': 'Cashier PIN ပြင်ဆင်ပြီးပါပြီ။',
    'Company deleted.': 'ကုမ္ပဏီ ဖျက်ပြီးပါပြီ။',
    'Company saved.': 'ကုမ္ပဏီ သိမ်းဆည်းပြီးပါပြီ။',
    'Company status updated.': 'ကုမ္ပဏီအခြေအနေ ပြင်ဆင်ပြီးပါပြီ။',
    'Create or activate the Cashier before managing the Cashier vault.':
        'Cashier ငွေတိုက်ကို စီမံမီ Cashier အကောင့်ကို ဖန်တီးပါ သို့မဟုတ် အသုံးပြုနိုင်အောင် ဖွင့်ပါ။',
    'Customer fee rules by provider, feature and amount range.':
        'ဝန်ဆောင်မှုပေးသူ၊ လုပ်ဆောင်ချက်နှင့် ပမာဏအပိုင်းအခြားအလိုက် ဖောက်သည်ဝန်ဆောင်ခ စည်းမျဉ်းများ။',
    'Customer fee rules by source provider, destination provider and amount range.':
        'မူလနှင့် သွားမည့်ဝန်ဆောင်မှုပေးသူ၊ ပမာဏအပိုင်းအခြားအလိုက် ဖောက်သည်ဝန်ဆောင်ခ စည်းမျဉ်းများ။',
    'Day closed.': 'နေ့စဉ်စာရင်း ပိတ်ပြီးပါပြီ။',
    'Delete agent commission tier?': 'အေးဂျင့်ကော်မရှင်အဆင့်ကို ဖျက်မလား?',
    'Delete fee tier?': 'ဝန်ဆောင်ခအဆင့်ကို ဖျက်မလား?',
    'Deleting...': 'ဖျက်နေပါသည်…',
    'Enter at least one banknote.': 'အနည်းဆုံး ငွေစက္ကူတစ်ရွက် ထည့်ပါ။',
    'Exchange rate deleted.': 'ငွေလဲနှုန်း ဖျက်ပြီးပါပြီ။',
    'Exchange rate saved.': 'ငွေလဲနှုန်း သိမ်းဆည်းပြီးပါပြီ။',
    'Issue an opening float or add more cash to an ACTIVE Teller float during the day.':
        'နေ့စတင်ငွေခွဲ ထုတ်ပေးပါ သို့မဟုတ် တစ်နေ့အတွင်း အသုံးပြုနေသော Teller ငွေခွဲထဲ ငွေထပ်ဖြည့်ပါ။',
    'Live note stock after issued Teller floats are removed.':
        'Teller များသို့ ထုတ်ပေးထားသော ငွေခွဲများကို နုတ်ပြီးနောက် လက်ရှိငွေစက္ကူလက်ကျန်။',
    'New passwords do not match.': 'စကားဝှက်အသစ် နှစ်ခု မကိုက်ညီပါ။',
    'Owner deposited cash into the Cashier main vault.':
        'Owner က Cashier Main Vault ထဲ ငွေသွင်းထားပါသည်။',
    'Owner withdrew cash from the Cashier main vault.':
        'Owner က Cashier Main Vault မှ ငွေထုတ်ထားပါသည်။',
    'PIN values do not match.': 'PIN နှစ်ခု မကိုက်ညီပါ။',
    'Password changed.': 'စကားဝှက် ပြောင်းပြီးပါပြီ။',
    'Provider customer fees': 'ဝန်ဆောင်မှုပေးသူ ဖောက်သည်ဝန်ဆောင်ခများ',
    'Read-only Teller Cash In history.':
        'Teller ငွေသွင်းမှတ်တမ်းကို ဖတ်ရှုရန်သာ။',
    'Read-only Teller Cash Out history.':
        'Teller ငွေထုတ်မှတ်တမ်းကို ဖတ်ရှုရန်သာ။',
    'Read-only Teller Exchange history.':
        'Teller ငွေလဲမှတ်တမ်းကို ဖတ်ရှုရန်သာ။',
    'Read-only Teller Transfer history.':
        'Teller ငွေလွှဲမှတ်တမ်းကို ဖတ်ရှုရန်သာ။',
    'Read-only Teller transaction history.':
        'Teller ငွေလုပ်ငန်းမှတ်တမ်းကို ဖတ်ရှုရန်သာ။',
    'Report refreshed.': 'အစီရင်ခံစာ ပြန်တင်ပြီးပါပြီ။',
    Reports: 'အစီရင်ခံစာများ',
    'Request failed.': 'တောင်းဆိုမှု မအောင်မြင်ပါ။',
    'Select a provider first.': 'ဝန်ဆောင်မှုပေးသူကို အရင်ရွေးပါ။',
    'Select a user first.': 'အသုံးပြုသူကို အရင်ရွေးပါ။',
    'Select an account first.': 'အကောင့်ကို အရင်ရွေးပါ။',
    'Teller float issued. Teller must review the note breakdown and receive it with PIN before use.':
        'Teller ငွေခွဲ ထုတ်ပေးပြီးပါပြီ။ အသုံးမပြုမီ Teller က ငွေစက္ကူစာရင်းကို စစ်ပြီး PIN ဖြင့် လက်ခံရပါမည်။',
    'Teller float return confirmed and added back to the main vault.':
        'Teller ပြန်အပ်ငွေကို အတည်ပြုပြီး Main Vault ထဲ ပြန်ထည့်ပြီးပါပြီ။',
    'This tier will be permanently deleted. Existing transaction snapshots remain unchanged.':
        'ဒီအဆင့်ကို အပြီးတိုင်ဖျက်ပါမည်။ ရှိပြီးသား ငွေလုပ်ငန်း snapshot များကို မပြောင်းလဲပါ။',
    'Transactions refreshed.': 'ငွေလုပ်ငန်းစာရင်းများ ပြန်တင်ပြီးပါပြီ။',
    'Unable to refresh.': 'ပြန်တင်၍ မရပါ။',
    'User PIN updated.': 'အသုံးပြုသူ PIN ပြင်ဆင်ပြီးပါပြီ။',
    'User password reset.': 'အသုံးပြုသူ စကားဝှက် ပြန်သတ်မှတ်ပြီးပါပြီ။',
    'User saved.': 'အသုံးပြုသူ သိမ်းဆည်းပြီးပါပြီ။',
    'User status updated.': 'အသုံးပြုသူအခြေအနေ ပြင်ဆင်ပြီးပါပြီ။',
    'Verify Teller float returns and add cash back to the vault.':
        'Teller များပြန်အပ်သော ငွေခွဲကို စစ်ဆေးပြီး ငွေတိုက်ထဲ ပြန်ထည့်ပါ။',
    'will be marked inactive and removed from selection lists. Existing balances, adjustments and transactions will be kept.':
        'ကို အသုံးမပြုအဖြစ် သတ်မှတ်ပြီး ရွေးချယ်စာရင်းများမှ ဖယ်ရှားပါမည်။ ရှိပြီးသားလက်ကျန်၊ ပြင်ဆင်မှုနှင့် ငွေလုပ်ငန်းစာရင်းများကို ဆက်လက်သိမ်းထားပါမည်။',
    'will be marked inactive and removed from selection lists. Existing records will be kept.':
        'ကို အသုံးမပြုအဖြစ် သတ်မှတ်ပြီး ရွေးချယ်စာရင်းများမှ ဖယ်ရှားပါမည်။ ရှိပြီးသားမှတ်တမ်းများကို ဆက်လက်သိမ်းထားပါမည်။',
    'verify the physical notes match the Teller-entered breakdown above. If they match, confirm with your PIN.':
        'အထက်ပါ Teller ထည့်ထားသော ငွေစက္ကူစာရင်းနှင့် လက်တွေ့ငွေစက္ကူများ ကိုက်ညီကြောင်း စစ်ပါ။ ကိုက်ညီပါက PIN ဖြင့် အတည်ပြုပါ။',
    'Access denied': 'ဝင်ရောက်ခွင့် မရှိပါ။',
    'Admin only.': 'Admin သာ လုပ်ဆောင်နိုင်ပါသည်။',
    'Admins cannot deactivate their own active session.':
        'Admin သည် လက်ရှိအသုံးပြုနေသော မိမိအကောင့်ကို ပိတ်၍မရပါ။',
    'Amount must be greater than zero.': 'ပမာဏသည် သုညထက် ကြီးရပါမည်။',
    'An active Cashier is required before the Owner can manage the Cashier vault.':
        'Owner က Cashier ငွေတိုက်ကို စီမံရန် အသုံးပြုနေသော Cashier အကောင့်တစ်ခု လိုအပ်ပါသည်။',
    'An active float is required before entering transactions.':
        'ငွေလုပ်ငန်းစာရင်းသွင်းမီ အသုံးပြုနေသော ငွေခွဲတစ်ခု လိုအပ်ပါသည်။',
    'Bank accounts cannot be agent accounts.':
        'Bank အကောင့်ကို Agent အကောင့်အဖြစ် သတ်မှတ်၍မရပါ။',
    'Bank providers do not support agent commission tiers. Choose a Pay provider.':
        'Bank ဝန်ဆောင်မှုပေးသူတွင် Agent commission tier မသတ်မှတ်နိုင်ပါ။ Pay ဝန်ဆောင်မှုပေးသူကို ရွေးပါ။',
    'Cash In cancelled.': 'ငွေသွင်းစာရင်း ပယ်ဖျက်ပြီးပါပြီ။',
    'Cash In confirmed.': 'ငွေသွင်းစာရင်း အတည်ပြုပြီးပါပြီ။',
    'Cash Out account-paid fees are credited to the selected account only.':
        'Cash Out အကောင့်ဖြင့်ပေးသော ဝန်ဆောင်ခကို ရွေးထားသောအကောင့်ထဲသာ ထည့်ပါမည်။',
    'Company already exists.': 'ဒီကုမ္ပဏီ ရှိပြီးသားဖြစ်ပါသည်။',
    'Currency must be MMK or THB.': 'ငွေကြေးသည် MMK သို့မဟုတ် THB ဖြစ်ရပါမည်။',
    'Current password is incorrect.': 'လက်ရှိစကားဝှက် မမှန်ကန်ပါ။',
    'Debit amount must be greater than zero.':
        'နုတ်မည့်ပမာဏသည် သုညထက် ကြီးရပါမည်။',
    'Denomination breakdown is required for Admin Cash Out from the main vault.':
        'Main Vault မှ Admin Cash Out လုပ်ရန် ငွေစက္ကူအသေးစိတ် လိုအပ်ပါသည်။',
    'Denomination breakdown is required for Cash In received cash.':
        'Cash In လက်ခံရငွေအတွက် ငွေစက္ကူအသေးစိတ် လိုအပ်ပါသည်။',
    'Denomination breakdown is required for Teller Cash In cashier handoff.':
        'Teller Cash In ကို Cashier ထံ အပ်ရန် ငွေစက္ကူအသေးစိတ် လိုအပ်ပါသည်။',
    'Denomination quantities do not match.': 'ငွေစက္ကူအရေအတွက်များ မကိုက်ညီပါ။',
    'Denomination quantity cannot be negative.':
        'ငွေစက္ကူအရေအတွက်သည် အနုတ်မဖြစ်ရပါ။',
    'Exchange payment method must be cash or account.':
        'ငွေလဲပေးချေနည်းသည် ငွေသား သို့မဟုတ် အကောင့် ဖြစ်ရပါမည်။',
    'Fee account must be empty when the fee is paid in cash.':
        'ဝန်ဆောင်ခကို ငွေသားပေးသောအခါ Fee account မရွေးရပါ။',
    'Fee denomination breakdown is required when Cash Out fee is received in cash.':
        'Cash Out ဝန်ဆောင်ခကို ငွေသားလက်ခံပါက ငွေစက္ကူအသေးစိတ် လိုအပ်ပါသည်။',
    'Fee denomination breakdown is required when Transfer fee is received in cash.':
        'Transfer ဝန်ဆောင်ခကို ငွေသားလက်ခံပါက ငွေစက္ကူအသေးစိတ် လိုအပ်ပါသည်။',
    'Fee payment method must be cash or account.':
        'ဝန်ဆောင်ခပေးချေနည်းသည် ငွေသား သို့မဟုတ် အကောင့် ဖြစ်ရပါမည်။',
    'Float increment must be greater than zero.':
        'ထပ်မံထုတ်ပေးမည့် ငွေခွဲပမာဏသည် သုညထက် ကြီးရပါမည်။',
    'Float must contain at least one denomination with quantity > 0.':
        'ငွေခွဲတွင် အရေအတွက် သုညထက်ကြီးသော ငွေစက္ကူအမျိုးအစား အနည်းဆုံးတစ်ခု ပါရပါမည်။',
    'Incorrect PIN': 'PIN မမှန်ကန်ပါ။',
    'Incorrect PIN.': 'PIN မမှန်ကန်ပါ။',
    'Invalid credentials': 'အသုံးပြုသူအမည် သို့မဟုတ် စကားဝှက် မမှန်ကန်ပါ။',
    'Invalid money amount.': 'ငွေပမာဏ မမှန်ကန်ပါ။',
    'No PIN set': 'PIN မသတ်မှတ်ရသေးပါ။',
    'No PIN set. Please set your PIN first.':
        'PIN မသတ်မှတ်ရသေးပါ။ PIN ကို အရင်သတ်မှတ်ပါ။',
    'No active float. Receive your float from the cashier first.':
        'အသုံးပြုနေသော ငွေခွဲမရှိပါ။ Cashier ထံမှ ငွေခွဲကို အရင်လက်ခံပါ။',
    'Old password incorrect.': 'စကားဝှက်အဟောင်း မမှန်ကန်ပါ။',
    'Only Cash In transactions can be cancelled here.':
        'ဒီနေရာတွင် Cash In စာရင်းကိုသာ ပယ်ဖျက်နိုင်ပါသည်။',
    'Only Cash In transactions can be confirmed here.':
        'ဒီနေရာတွင် Cash In စာရင်းကိုသာ အတည်ပြုနိုင်ပါသည်။',
    'Only a cashier can cancel Cash In transactions.':
        'Cash In စာရင်းကို Cashier သာ ပယ်ဖျက်နိုင်ပါသည်။',
    'Only a cashier can confirm Cash In transactions.':
        'Cash In စာရင်းကို Cashier သာ အတည်ပြုနိုင်ပါသည်။',
    'Only one Cashier account is allowed. Update the existing Cashier instead of creating or assigning another one.':
        'Cashier အကောင့်တစ်ခုသာ ခွင့်ပြုထားပါသည်။ နောက်တစ်ခုဖန်တီးမည့်အစား ရှိပြီးသား Cashier ကို ပြင်ဆင်ပါ။',
    'Only pending Cash In notifications can be marked as read.':
        'စောင့်ဆိုင်းနေသော Cash In အသိပေးချက်များကိုသာ ဖတ်ပြီးအဖြစ် သတ်မှတ်နိုင်ပါသည်။',
    'PIN is required.': 'PIN လိုအပ်ပါသည်။',
    'PIN must be 4-8 digits.': 'PIN သည် ဂဏန်း ၄ မှ ၈ လုံး ဖြစ်ရပါမည်။',
    'PIN must be 4–8 digits.': 'PIN သည် ဂဏန်း ၄ မှ ၈ လုံး ဖြစ်ရပါမည်။',
    'Receive or reject all pending additional float issues before returning this float.':
        'ဒီငွေခွဲကို ပြန်မအပ်မီ စောင့်ဆိုင်းနေသော ထပ်မံငွေခွဲအားလုံးကို လက်ခံ သို့မဟုတ် ပယ်ချပါ။',
    'Selected fee account is not active or is not marked as a fee account.':
        'ရွေးထားသော Fee account သည် အသုံးမပြုနိုင်ပါ သို့မဟုတ် Fee account အဖြစ် မသတ်မှတ်ထားပါ။',
    'Source account and fee account must be different.':
        'မူလအကောင့်နှင့် Fee account မတူရပါ။',
    'Source and target accounts must be different.':
        'မူလအကောင့်နှင့် သွားမည့်အကောင့် မတူရပါ။',
    'This provider already has an account with the same identifier.':
        'ဒီဝန်ဆောင်မှုပေးသူတွင် အလားတူအကောင့်နံပါတ် ရှိပြီးသားဖြစ်ပါသည်။',
    'This provider already has an active agent commission tier overlapping the amount range.':
        'ဒီဝန်ဆောင်မှုပေးသူတွင် ပမာဏအပိုင်းအခြား ထပ်နေသော အသုံးပြုနေသည့် Agent commission tier ရှိပြီးသားဖြစ်ပါသည်။',
    'This provider and feature already has an active customer fee tier overlapping the amount range.':
        'ဒီဝန်ဆောင်မှုပေးသူနှင့် လုပ်ဆောင်ချက်တွင် ပမာဏအပိုင်းအခြား ထပ်နေသော Customer fee tier ရှိပြီးသားဖြစ်ပါသည်။',
    'This provider route already has an active transfer fee tier overlapping the amount range.':
        'ဒီဝန်ဆောင်မှုလမ်းကြောင်းတွင် ပမာဏအပိုင်းအခြား ထပ်နေသော Transfer fee tier ရှိပြီးသားဖြစ်ပါသည်။',
    'Total amount does not match.': 'စုစုပေါင်းပမာဏ မကိုက်ညီပါ။',
    'Transfer account-paid fees are credited to the system receive account.':
        'Transfer အကောင့်ဖြင့်ပေးသော ဝန်ဆောင်ခကို System လက်ခံအကောင့်ထဲ ထည့်ပါမည်။',
    'Unable to adjust an inactive account.':
        'အသုံးမပြုသောအကောင့်၏ လက်ကျန်ကို ပြင်ဆင်၍မရပါ။',
    'Unsupported denomination:': 'မပံ့ပိုးသော ငွေစက္ကူအမျိုးအစား:',
    'Use the review step before confirming a transaction.':
        'ငွေလုပ်ငန်းကို အတည်မပြုမီ Review အဆင့်ကို အသုံးပြုပါ။',
    'Username or email already exists.':
        'အသုံးပြုသူအမည် သို့မဟုတ် အီးမေးလ် ရှိပြီးသားဖြစ်ပါသည်။',
    Account: 'အကောင့်',
    'Account Detail': 'အကောင့်အသေးစိတ်',
    'Account Features': 'အကောင့်လုပ်ဆောင်ချက်များ',
    'Account Name': 'အကောင့်အမည်',
    'Account Type': 'အကောင့်အမျိုးအစား',
    'Account not found.': 'အကောင့် မတွေ့ပါ။',
    'Account security': 'အကောင့်လုံခြုံရေး',
    Accounts: 'အကောင့်များ',
    Action: 'လုပ်ဆောင်ချက်',
    Actions: 'လုပ်ဆောင်ချက်များ',
    Active: 'အသုံးပြုနေသည်',
    'Add tier': 'အဆင့်အသစ်ထည့်မည်',
    'Additional fee': 'အပိုဝန်ဆောင်ခ',
    Admin: 'စနစ်စီမံခန့်ခွဲသူ',
    Agent: 'အေးဂျင့်',
    'Agent Account': 'အေးဂျင့်အကောင့်',
    'Agent Commissions': 'အေးဂျင့်ကော်မရှင်များ',
    'Agent account': 'အေးဂျင့်အကောင့်',
    'Agent commission': 'အေးဂျင့်ကော်မရှင်',
    'Agent commission entries': 'အေးဂျင့်ကော်မရှင်စာရင်းများ',
    All: 'အားလုံး',
    'All Transactions': 'ငွေလုပ်ငန်းစာရင်းအားလုံး',
    'All directions': 'ဦးတည်ချက်အားလုံး',
    'All floats': 'ငွေခွဲအားလုံး',
    'All movements': 'ငွေရွှေ့ပြောင်းမှုအားလုံး',
    'All statuses': 'အခြေအနေအားလုံး',
    'All types': 'အမျိုးအစားအားလုံး',
    Amount: 'ပမာဏ',
    'Amount from': 'ပမာဏမှ',
    'Amount range': 'ပမာဏအပိုင်းအခြား',
    'Amount to': 'ပမာဏအထိ',
    Apply: 'အသုံးပြုမည်',
    'Apply Adjustment': 'လက်ကျန်ပြင်ဆင်မည်',
    'Audit note': 'စာရင်းစစ်မှတ်ချက်',
    'Available counter cash': 'ကောင်တာတွင် အသုံးပြုနိုင်သောငွေ',
    'Available stock is the live main vault balance after issued Teller floats are removed. Only the Owner/Admin can deposit, withdraw, or manually adjust this vault.':
        'အသုံးပြုနိုင်သောငွေသည် Teller များသို့ ထုတ်ပေးထားသော ငွေခွဲများကို နုတ်ပြီးနောက် လက်ရှိ Main Vault လက်ကျန်ဖြစ်ပါသည်။ ဒီငွေတိုက်ကို Owner/Admin သာ ငွေသွင်း၊ ငွေထုတ် သို့မဟုတ် လက်ကျန်ပြင်ဆင်နိုင်ပါသည်။',
    Back: 'နောက်သို့',
    Balance: 'လက်ကျန်',
    'Balance Adjust': 'လက်ကျန်ပြင်ဆင်မှု',
    Bank: 'ဘဏ်',
    Base: 'အခြေခံငွေကြေး',
    'Base Amount': 'အခြေခံပမာဏ',
    Both: 'နှစ်မျိုးလုံး',
    Breadcrumb: 'လမ်းညွှန်',
    'Buy Rate': 'ဝယ်ဈေး',
    By: 'လုပ်ဆောင်သူ',
    'By:': 'လုပ်ဆောင်သူ:',
    Cancel: 'မလုပ်တော့ပါ',
    Cash: 'ငွေသား',
    'Cash Floats': 'ငွေခွဲစာရင်းများ',
    'Cash In': 'ငွေသွင်း',
    'Cash In Transactions': 'ငွေသွင်းစာရင်းများ',
    'Cash In history': 'ငွေသွင်းမှတ်တမ်း',
    'Cash In history filters': 'ငွေသွင်းမှတ်တမ်း စစ်ထုတ်ရန်',
    'Cash In, Cash Out, Send Money and Receive Money fees are separate feature rows.':
        'ငွေသွင်း၊ ငွေထုတ်၊ ငွေပို့ နှင့် ငွေလက်ခံ ဝန်ဆောင်ခများကို သီးခြားလုပ်ဆောင်ချက်အဖြစ် သတ်မှတ်ထားပါသည်။',
    'Cash Movement Reconciliation': 'ငွေသားရွှေ့ပြောင်းမှု စာရင်းညှိခြင်း',
    'Cash Out': 'ငွေထုတ်',
    'Cash Out Transactions': 'ငွေထုတ်စာရင်းများ',
    'Cash Out cash settlement': 'ငွေထုတ် ငွေသားရှင်းတမ်း',
    'Cash Out history': 'ငွေထုတ်မှတ်တမ်း',
    'Cash Out history filters': 'ငွေထုတ်မှတ်တမ်း စစ်ထုတ်ရန်',
    'Cash float notes': 'ငွေခွဲမှတ်ချက်',
    Cashier: 'ငွေတိုက်တာဝန်ခံ',
    'Cashier Main Vault': 'Cashier အဓိကငွေတိုက်',
    'Cashier approval PIN': 'Cashier အတည်ပြု PIN',
    'Cashier dashboard': 'Cashier လုပ်ငန်းအနှစ်ချုပ်',
    'Cashier handoff': 'Cashier ထံ အပ်ငွေ',
    'Cashier profile': 'Cashier ကိုယ်ရေးအချက်အလက်',
    Category: 'အမျိုးအစား',
    'Change given': 'ပြန်အမ်းငွေ',
    'Change password': 'စကားဝှက်ပြောင်းမည်',
    'Choose a Teller': 'Teller ရွေးပါ',
    Clear: 'ရှင်းမည်',
    Close: 'ပိတ်မည်',
    'Close Day': 'နေ့စဉ်စာရင်းပိတ်မည်',
    'Close denomination review': 'ငွေစက္ကူစစ်ဆေးမှု ပိတ်မည်',
    'Closed By': 'စာရင်းပိတ်သူ',
    'Closing Notes': 'စာရင်းပိတ်မှတ်ချက်',
    Companies: 'ကုမ္ပဏီများ',
    Company: 'ကုမ္ပဏီ',
    'Company name': 'ကုမ္ပဏီအမည်',
    'Company not found.': 'ကုမ္ပဏီ မတွေ့ပါ။',
    'Company to credit': 'ငွေဝင်မည့်ကုမ္ပဏီ',
    'Completed / other': 'ပြီးမြောက် / အခြား',
    'Configure PAY agent earnings by account money movement. OUT / Send and IN / Receive share one calculation type for each amount range.':
        'PAY အေးဂျင့်ရရှိမည့် ကော်မရှင်ကို အကောင့်ငွေလှုပ်ရှားမှုအလိုက် သတ်မှတ်ပါ။ ပမာဏအပိုင်းအခြားတစ်ခုစီအတွက် OUT / Send နှင့် IN / Receive သည် တွက်ချက်နည်းတစ်မျိုးတည်းကို သုံးပါသည်။',
    'Confirm Cash In': 'ငွေသွင်းအတည်ပြုမည်',
    'Confirm PIN': 'PIN အတည်ပြုမည်',
    'Confirm Teller return': 'Teller ပြန်အပ်ငွေ အတည်ပြုမည်',
    'Confirm password': 'စကားဝှက်အတည်ပြု',
    'Confirm with PIN': 'PIN ဖြင့် အတည်ပြုမည်',
    'Count the physical handoff before confirming into the main vault.':
        'Main Vault ထဲ စာရင်းမသွင်းမီ လက်တွေ့အပ်နှံထားသော ငွေကို ရေတွက်စစ်ဆေးပါ။',
    Create: 'အသစ်ဖန်တီးမည်',
    Created: 'ဖန်တီးချိန်',
    'Created By': 'ဖန်တီးသူ',
    Credentials: 'ဝင်ရောက်ခွင့်အချက်အလက်',
    Current: 'လက်ရှိ',
    'Current password': 'လက်ရှိစကားဝှက်',
    Customer: 'ဖောက်သည်',
    'Customer fee': 'ဖောက်သည်ဝန်ဆောင်ခ',
    'Customer received': 'ဖောက်သည်လက်ခံရငွေ',
    'Daily Closing': 'နေ့စဉ်စာရင်းပိတ်ခြင်း',
    'Daily Position': 'နေ့စဉ်ငွေအခြေအနေ',
    Date: 'ရက်စွဲ',
    Delete: 'ဖျက်မည်',
    'Delete account?': 'အကောင့်ကို ဖျက်မလား?',
    'Delete company?': 'ကုမ္ပဏီကို ဖျက်မလား?',
    'Delete exchange rate': 'ငွေလဲနှုန်းဖျက်မည်',
    'Delete exchange rate?': 'ငွေလဲနှုန်းကို ဖျက်မလား?',
    Denomination: 'ငွေစက္ကူအမျိုးအစား',
    'Deposit to Cashier': 'Cashier ထံ ငွေသွင်းမည်',
    Details: 'အသေးစိတ်',
    Digital: 'ဒစ်ဂျစ်တယ်ငွေ',
    Direction: 'ဦးတည်ချက်',
    Earned: 'ရရှိပြီး',
    Edit: 'ပြင်ဆင်မည်',
    Email: 'အီးမေးလ်',
    Employee: 'ဝန်ထမ်း',
    'Employee Cash': 'ဝန်ထမ်းကိုင်ဆောင်ငွေ',
    'End-of-day': 'နေ့ကုန်စာရင်း',
    'Enter Details': 'အသေးစိတ်ဖြည့်ပါ',
    Entity: 'မှတ်တမ်းအမျိုးအစား',
    'Every note movement is recorded with its operator and reason.':
        'ငွေစက္ကူရွှေ့ပြောင်းမှုတိုင်းကို လုပ်ဆောင်သူနှင့် အကြောင်းပြချက်တို့ဖြင့် မှတ်တမ်းတင်ထားပါသည်။',
    Exchange: 'ငွေလဲ',
    'Exchange Rates': 'ငွေလဲနှုန်းများ',
    'Exchange Transactions': 'ငွေလဲစာရင်းများ',
    'Exchange history': 'ငွေလဲမှတ်တမ်း',
    'Exchange history filters': 'ငွေလဲမှတ်တမ်း စစ်ထုတ်ရန်',
    'Exchange rate not found.': 'ငွေလဲနှုန်း မတွေ့ပါ။',
    'Expected return': 'မျှော်မှန်းပြန်အပ်ငွေ',
    'Expected settlement': 'မျှော်မှန်းရှင်းတမ်း',
    Feature: 'လုပ်ဆောင်ချက်',
    Features: 'လုပ်ဆောင်ချက်များ',
    Fee: 'ဝန်ဆောင်ခ',
    'Fee Account': 'ဝန်ဆောင်ခအကောင့်',
    'Fee Accounts': 'ဝန်ဆောင်ခအကောင့်များ',
    'Fee account': 'ဝန်ဆောင်ခအကောင့်',
    Fees: 'ဝန်ဆောင်ခများ',
    Filter: 'စစ်ထုတ်မည်',
    Flags: 'သတ်မှတ်ချက်များ',
    Float: 'ငွေခွဲ',
    'Float cash paid': 'ငွေခွဲမှ ပေးထားသောငွေ',
    'Float:': 'ငွေခွဲ:',
    Flow: 'ငွေစီးဆင်းမှု',
    'Flow:': 'ငွေစီးဆင်းမှု:',
    From: 'မှ',
    'From date': 'စတင်ရက်',
    'From provider': 'မူလဝန်ဆောင်မှုပေးသူ',
    'Full Name': 'အမည်အပြည့်အစုံ',
    Grand: 'စုစုပေါင်း',
    'Grand Total': 'စုစုပေါင်းအားလုံး',
    'IN / Receive': 'ဝင် / လက်ခံ',
    'IN earns': 'ဝင်ငွေအတွက်ရရှိမည်',
    Identifier: 'အကောင့်နံပါတ် / အမှတ်အသား',
    Inactive: 'အသုံးမပြုပါ',
    Issue: 'ထုတ်ပေးမည်',
    'Issue / add Teller float': 'Teller ငွေခွဲ ထုတ်ပေး / ထပ်ဖြည့်',
    'Issue Teller float': 'Teller ငွေခွဲထုတ်ပေးမည်',
    'Issue or review floats →': 'ငွေခွဲထုတ်ပေး / စစ်ဆေးရန် →',
    'Issue the opening float, or issue more cash to the same ACTIVE float during the day.':
        'နေ့စတင်ငွေခွဲ ထုတ်ပေးပါ သို့မဟုတ် တစ်နေ့အတွင်း အသုံးပြုနေသော ငွေခွဲထဲ ထပ်မံငွေထည့်ပေးပါ။',
    Issued: 'ထုတ်ပေးထားငွေ',
    'Issued By': 'ထုတ်ပေးသူ',
    'Language selector': 'ဘာသာစကားရွေးချယ်ရန်',
    'Latest counter activity': 'လတ်တလောကောင်တာလှုပ်ရှားမှု',
    'Line chart': 'လိုင်းဇယား',
    List: 'စာရင်း',
    Load: 'ဖွင့်မည်',
    Logo: 'လိုဂို',
    'Main vault': 'အဓိကငွေတိုက်',
    'Main vault audit log': 'အဓိကငွေတိုက် စာရင်းစစ်မှတ်တမ်း',
    'Main vault balance': 'အဓိကငွေတိုက်လက်ကျန်',
    'Main vault cash': 'အဓိကငွေတိုက် ငွေသား',
    'Main vault denomination stock': 'အဓိကငွေတိုက် ငွေစက္ကူလက်ကျန်',
    'Main vault, Teller floats and pending work at a glance.':
        'အဓိကငွေတိုက်၊ Teller ငွေခွဲနှင့် စောင့်ဆိုင်းနေသောလုပ်ငန်းများကို တစ်နေရာတည်းတွင် ကြည့်ရှုနိုင်ပါသည်။',
    'Manage your Cashier identity, password, and approval PIN.':
        'Cashier ကိုယ်ရေးအချက်အလက်၊ စကားဝှက်နှင့် အတည်ပြု PIN ကို စီမံပါ။',
    Matched: 'ကိုက်ညီသည်',
    'Money transfer operations': 'ငွေလွှဲလုပ်ငန်းစနစ်',
    Movement: 'ငွေရွှေ့ပြောင်းမှု',
    Name: 'အမည်',
    'New PIN': 'PIN အသစ်',
    'New Password': 'စကားဝှက်အသစ်',
    'New password': 'စကားဝှက်အသစ်',
    'New transaction': 'ငွေလုပ်ငန်းအသစ်',
    Next: 'နောက်တစ်မျက်နှာ',
    'New Teller Cash In entries waiting for cashier review.':
        'Cashier စစ်ဆေးရန် စောင့်နေသော Teller ငွေသွင်းစာရင်းအသစ်များ။',
    'No Teller entry is waiting for Cashier action.':
        'Cashier လုပ်ဆောင်ရန် စောင့်နေသော Teller စာရင်းမရှိပါ။',
    'No Teller transactions yet.': 'Teller ငွေလုပ်ငန်းစာရင်း မရှိသေးပါ။',
    'No account snapshot yet.': 'အကောင့်လက်ကျန်မှတ်တမ်း မရှိသေးပါ။',
    'No denomination breakdown.': 'ငွေစက္ကူအသေးစိတ် မရှိပါ။',
    'No linked cash denomination rows.':
        'ချိတ်ဆက်ထားသော ငွေစက္ကူစာရင်း မရှိပါ။',
    'No matching Teller entry.': 'ကိုက်ညီသော Teller စာရင်း မတွေ့ပါ။',
    'No matching Teller floats.': 'ကိုက်ညီသော Teller ငွေခွဲ မတွေ့ပါ။',
    'No reconciliation records found.': 'စာရင်းညှိမှတ်တမ်း မတွေ့ပါ။',
    'No transactions found.': 'ငွေလုပ်ငန်းစာရင်း မတွေ့ပါ။',
    'No transactions match your search.':
        'ရှာဖွေမှုနှင့် ကိုက်ညီသော ငွေလုပ်ငန်းစာရင်း မရှိပါ။',
    'No vault logs found.': 'ငွေတိုက်မှတ်တမ်း မတွေ့ပါ။',
    'No vault movements yet.': 'ငွေတိုက်ရွှေ့ပြောင်းမှု မရှိသေးပါ။',
    Note: 'မှတ်ချက်',
    'Note:': 'မှတ်ချက်:',
    Notes: 'မှတ်ချက်များ',
    'OUT / Send': 'ထွက် / ပို့',
    'OUT earns': 'ထွက်ငွေအတွက်ရရှိမည်',
    'Open Teller floats': 'ဖွင့်ထားသော Teller ငွေခွဲများ',
    'Operating Coverage': 'လုပ်ငန်းလည်ပတ်မှုအကျယ်အဝန်း',
    Operator: 'လုပ်ဆောင်သူ',
    'Optional note': 'မှတ်ချက် (မဖြည့်လည်းရ)',
    'Owner Console': 'Owner စီမံခန့်ခွဲရေး',
    'Owner Console / Pricing Rules':
        'Owner စီမံခန့်ခွဲရေး / ဝန်ဆောင်ခစည်းမျဉ်းများ',
    'Owner managed · read only': 'Owner စီမံ · ဖတ်ရှုရန်သာ',
    'Owner-managed physical cash. Cashier can view this vault but cannot manually add, remove, or adjust cash.':
        'Owner စီမံသည့် လက်တွေ့ငွေသားဖြစ်ပါသည်။ Cashier က လက်ကျန်ကို ကြည့်နိုင်သော်လည်း ကိုယ်တိုင် ငွေထည့်၊ ငွေထုတ် သို့မဟုတ် လက်ကျန်ပြင်ဆင်၍မရပါ။',
    PIN: 'PIN',
    'PIN confirmation': 'PIN အတည်ပြုခြင်း',
    Pair: 'ငွေကြေးအတွဲ',
    Password: 'စကားဝှက်',
    Pay: 'Pay',
    'Pending Cash In': 'စောင့်ဆိုင်းနေသော ငွေသွင်း',
    'Pending Cash In reviews': 'စစ်ဆေးရန် ငွေသွင်းစာရင်းများ',
    'Pending returns': 'စောင့်ဆိုင်းနေသော ပြန်အပ်ငွေများ',
    'Pending total': 'စောင့်ဆိုင်းငွေစုစုပေါင်း',
    'Preview amount': 'နမူနာပမာဏ',
    Previous: 'ရှေ့တစ်မျက်နှာ',
    Profit: 'အမြတ်',
    Provider: 'ဝန်ဆောင်မှုပေးသူ',
    'Provider / Feature': 'ဝန်ဆောင်မှုပေးသူ / လုပ်ဆောင်ချက်',
    'Provider Fees': 'ဝန်ဆောင်မှုပေးသူ ဝန်ဆောင်ခများ',
    Qty: 'အရေအတွက်',
    Quantity: 'အရေအတွက်',
    'Quick actions': 'အမြန်လုပ်ဆောင်ချက်များ',
    Quote: 'နှိုင်းယှဉ်ငွေကြေး',
    'Reason for audit log': 'စာရင်းစစ်အကြောင်းပြချက်',
    'Receive Money': 'ငွေလက်ခံ',
    'Receiver check:': 'လက်ခံသူစစ်ဆေးရန်:',
    'Recent Teller transactions': 'လတ်တလော Teller ငွေလုပ်ငန်းများ',
    'Recent entries': 'လတ်တလောစာရင်းများ',
    Reconcile: 'စာရင်းညှိခြင်း',
    'Reconcile end-of-day return': 'နေ့ကုန်ပြန်အပ်ငွေ စာရင်းညှိမည်',
    'Reconciliation History': 'စာရင်းညှိမှတ်တမ်း',
    'One physical cash movement is one audit row. Banknote denominations are available in Details.':
        'လက်တွေ့ငွေသား ရွှေ့ပြောင်းမှုတစ်ခုကို Audit စာရင်းတစ်ကြောင်းအဖြစ်သာ ပြပါမည်။ ငွေစက္ကူအမျိုးအစားအသေးစိတ်ကို Details တွင် ကြည့်နိုင်ပါသည်။',
    'Search reference, movement, note, operator or denomination':
        'Reference၊ ရွှေ့ပြောင်းမှု၊ မှတ်ချက်၊ လုပ်ဆောင်သူ သို့မဟုတ် ငွေစက္ကူအမျိုးအစားဖြင့် ရှာပါ',
    'Reason / Note': 'အကြောင်းပြချက် / မှတ်ချက်',
    'Banknote breakdown': 'ငွေစက္ကူအသေးစိတ်',
    Banknote: 'ငွေစက္ကူ',
    'Reconciliation issues': 'စာရင်းညှိ ပြဿနာများ',
    Ref: 'ရည်ညွှန်း',
    'Ref, customer, status': 'ရည်ညွှန်း၊ ဖောက်သည်၊ အခြေအနေ',
    Reference: 'ရည်ညွှန်းနံပါတ်',
    'Reference, customer, phone, account':
        'ရည်ညွှန်း၊ ဖောက်သည်၊ ဖုန်း၊ အကောင့်',
    'Reference:': 'ရည်ညွှန်းနံပါတ်:',
    Refresh: 'ပြန်တင်မည်',
    'Refresh Summary': 'အနှစ်ချုပ် ပြန်တင်မည်',
    'Reject Cash In': 'ငွေသွင်း ပယ်ချမည်',
    Remark: 'မှတ်ချက်',
    'Reset Password': 'စကားဝှက် ပြန်သတ်မှတ်မည်',
    Returned: 'ပြန်အပ်ပြီး',
    'Returns to reconcile': 'စာရင်းညှိရန် ပြန်အပ်ငွေများ',
    'Review handoff #': 'အပ်ငွေ စစ်ဆေးရန် #',
    'Review mode': 'စစ်ဆေးမှုအခြေအနေ',
    'Review pending Cash In': 'စောင့်ဆိုင်းနေသော ငွေသွင်း စစ်ဆေးမည်',
    'Review the Teller-entered note breakdown against the physical cash, then confirm receipt with your Cashier PIN.':
        'Teller ထည့်ထားသော ငွေစက္ကူစာရင်းကို လက်တွေ့ငွေသားနှင့် တိုက်စစ်ပြီးနောက် Cashier PIN ဖြင့် လက်ခံကြောင်း အတည်ပြုပါ။',
    Role: 'ရာထူး / အခန်းကဏ္ဍ',
    'Rounded customer fee': 'အဝိုင်းချထားသော ဖောက်သည်ဝန်ဆောင်ခ',
    Route: 'လမ်းကြောင်း',
    Rule: 'စည်းမျဉ်း',
    Search: 'ရှာဖွေမည်',
    'Search Teller or float': 'Teller သို့မဟုတ် ငွေခွဲ ရှာပါ',
    'Search account, provider, type, identifier':
        'အကောင့်၊ ဝန်ဆောင်မှုပေးသူ၊ အမျိုးအစား၊ နံပါတ် ရှာပါ',
    'Search company': 'ကုမ္ပဏီ ရှာပါ',
    'Search currency or rate': 'ငွေကြေး သို့မဟုတ် နှုန်းထား ရှာပါ',
    'Search date, closed by, notes or total':
        'ရက်စွဲ၊ စာရင်းပိတ်သူ၊ မှတ်ချက် သို့မဟုတ် စုစုပေါင်း ရှာပါ',
    'Search movement, note, operator or denomination':
        'ငွေရွှေ့ပြောင်းမှု၊ မှတ်ချက်၊ လုပ်ဆောင်သူ သို့မဟုတ် ငွေစက္ကူ ရှာပါ',
    'Search provider, feature, route or amount':
        'ဝန်ဆောင်မှုပေးသူ၊ လုပ်ဆောင်ချက်၊ လမ်းကြောင်း သို့မဟုတ် ပမာဏ ရှာပါ',
    'Search reference, customer or teller':
        'ရည်ညွှန်း၊ ဖောက်သည် သို့မဟုတ် Teller ရှာပါ',
    'Search reference, customer, status': 'ရည်ညွှန်း၊ ဖောက်သည်၊ အခြေအနေ ရှာပါ',
    'Search reference, flow, transaction, note or denomination':
        'ရည်ညွှန်း၊ ငွေစီးဆင်းမှု၊ ငွေလုပ်ငန်း၊ မှတ်ချက် သို့မဟုတ် ငွေစက္ကူ ရှာပါ',
    'Search staff, username, email or role':
        'ဝန်ထမ်း၊ အသုံးပြုသူအမည်၊ အီးမေးလ် သို့မဟုတ် ရာထူး ရှာပါ',
    'Select account': 'အကောင့်ရွေးပါ',
    'Select user': 'အသုံးပြုသူရွေးပါ',
    'Selected:': 'ရွေးထားသည်:',
    'Sell Rate': 'ရောင်းဈေး',
    'Send Money': 'ငွေပို့',
    'Set PIN': 'PIN သတ်မှတ်မည်',
    Show: 'ပြမည်',
    'Signed Amount': 'အပေါင်း/အနုတ် ပမာဏ',
    'Skip to content': 'အကြောင်းအရာသို့ ကျော်သွားရန်',
    'Source company': 'မူလကုမ္ပဏီ',
    'Staff Detail': 'ဝန်ထမ်းအသေးစိတ်',
    Status: 'အခြေအနေ',
    'Status:': 'အခြေအနေ:',
    'System payout company': 'System ငွေပို့မည့်ကုမ္ပဏီ',
    Teller: 'ငွေကိုင်ဝန်ထမ်း',
    'Teller Cash In': 'Teller ငွေသွင်း',
    'Teller entry history': 'Teller စာရင်းမှတ်တမ်း',
    'Teller entry notifications': 'Teller စာရင်းအသိပေးချက်များ',
    'Teller float issue': 'Teller ငွေခွဲထုတ်ပေးမှု',
    'Teller handback': 'Teller ပြန်အပ်ငွေ',
    'Teller reconciliation': 'Teller စာရင်းညှိခြင်း',
    'Teller reported handback': 'Teller တင်ပြထားသော ပြန်အပ်ငွေ',
    'Teller return': 'Teller ပြန်အပ်ငွေ',
    'This action is recorded in both Vault Log and Activity Logs under the signed-in Owner/Admin.':
        'ဒီလုပ်ဆောင်ချက်ကို လက်ရှိဝင်ရောက်ထားသော Owner/Admin အမည်ဖြင့် Vault Log နှင့် Activity Logs နှစ်ခုလုံးတွင် မှတ်တမ်းတင်ပါမည်။',
    'Tier detail': 'အဆင့်အသေးစိတ်',
    Time: 'အချိန်',
    To: 'သို့',
    'To date': 'အဆုံးရက်',
    'To provider': 'သွားမည့်ဝန်ဆောင်မှုပေးသူ',
    Total: 'စုစုပေါင်း',
    'Total Physical Cash': 'လက်တွေ့ငွေသားစုစုပေါင်း',
    'Transaction Detail': 'ငွေလုပ်ငန်းအသေးစိတ်',
    'Transaction not found.': 'ငွေလုပ်ငန်းစာရင်း မတွေ့ပါ။',
    'Transaction:': 'ငွေလုပ်ငန်း:',
    Transactions: 'ငွေလုပ်ငန်းစာရင်းများ',
    Transfer: 'ငွေလွှဲ',
    'Transfer Fees': 'ငွေလွှဲဝန်ဆောင်ခများ',
    'Transfer Transactions': 'ငွေလွှဲစာရင်းများ',
    'Transfer customer fee is route-based. Agent commissions are configured separately under Agent Commissions.':
        'ငွေလွှဲဖောက်သည်ဝန်ဆောင်ခကို လမ်းကြောင်းအလိုက် သတ်မှတ်ပါသည်။ အေးဂျင့်ကော်မရှင်ကို Agent Commissions တွင် သီးခြားသတ်မှတ်ပါ။',
    'Transfer fee': 'ငွေလွှဲဝန်ဆောင်ခ',
    'Transfer fees': 'ငွေလွှဲဝန်ဆောင်ခများ',
    'Transfer history': 'ငွေလွှဲမှတ်တမ်း',
    'Transfer history filters': 'ငွေလွှဲမှတ်တမ်း စစ်ထုတ်ရန်',
    Type: 'အမျိုးအစား',
    Updated: 'ပြင်ဆင်ချိန်',
    User: 'အသုံးပြုသူ',
    'User not found.': 'အသုံးပြုသူ မတွေ့ပါ။',
    Username: 'အသုံးပြုသူအမည်',
    Users: 'အသုံးပြုသူများ',
    Vault: 'ငွေတိုက်',
    'Vault Log': 'ငွေတိုက်မှတ်တမ်း',
    'Verify return': 'ပြန်အပ်ငွေ စစ်ဆေးမည်',
    'Verify the Teller handoff before posting cash to the main vault.':
        'Main Vault ထဲ စာရင်းမသွင်းမီ Teller အပ်ငွေကို စစ်ဆေးပါ။',
    'Verify the physical notes returned by each Teller before closing the float.':
        'ငွေခွဲစာရင်းမပိတ်မီ Teller တစ်ဦးစီ ပြန်အပ်သည့် လက်တွေ့ငွေစက္ကူများကို စစ်ဆေးပါ။',
    View: 'ကြည့်မည်',
    'View all →': 'အားလုံးကြည့်မည် →',
    'View cash float →': 'ငွေခွဲကြည့်မည် →',
    'View denomination stock →': 'ငွေစက္ကူလက်ကျန်ကြည့်မည် →',
    'View issued notes': 'ထုတ်ပေးထားသော ငွေစက္ကူကြည့်မည်',
    'Waiting for Cashier confirmation': 'Cashier အတည်ပြုရန် စောင့်နေသည်',
    'Withdraw from Cashier': 'Cashier ထံမှ ငွေထုတ်မည်',
    'You will be signed out after a successful password change.':
        'စကားဝှက်ပြောင်းပြီးလျှင် စနစ်မှ အလိုအလျောက်ထွက်ပါမည်။',
    active: 'အသုံးပြုနေသည်',
    inactive: 'အသုံးမပြုပါ',
    pending: 'စောင့်ဆိုင်းနေသည်',
    completed: 'ပြီးမြောက်သည်',
    cancelled: 'ပယ်ဖျက်ထားသည်',
    closed: 'ပိတ်ထားသည်',
    'pending cashier confirm': 'Cashier အတည်ပြုရန် စောင့်နေသည်',
    'pending reconciliation': 'စာရင်းညှိရန် စောင့်နေသည်',
    matched: 'ကိုက်ညီသည်',
    mismatch: 'မကိုက်ညီပါ',
    'missing cash log': 'ငွေသားမှတ်တမ်း မရှိပါ',
    'legacy / unlinked': 'အဟောင်း / မချိတ်ဆက်ထား',
    'n/a': 'မသက်ဆိုင်ပါ',
    entries: 'စာရင်း',
    transactions: 'ငွေလုပ်ငန်းစာရင်း',
    'A denomination would go below zero. Adjust payout or change notes.':
        'ငွေစက္ကူအမျိုးအစားတစ်ခု၏ လက်ကျန် သုညအောက်ကျသွားနိုင်ပါသည်။ ပေးငွေ သို့မဟုတ် ပြန်အမ်းငွေစက္ကူကို ပြန်ညှိပါ။',
    'Activate your teller float first.': 'Teller ငွေခွဲကို အရင်ဖွင့်ပါ။',
    'Admin console balance adjustment.': 'Admin Console မှ လက်ကျန်ပြင်ဆင်မှု။',
    'Agent earning rules by provider and amount range. OUT / Send and IN / Receive values share one calculation type.':
        'ဝန်ဆောင်မှုပေးသူနှင့် ပမာဏအပိုင်းအခြားအလိုက် အေးဂျင့်ရရှိမည့် ကော်မရှင်စည်းမျဉ်းများ။ OUT / Send နှင့် IN / Receive သည် တွက်ချက်နည်းတစ်မျိုးတည်းကို အသုံးပြုပါသည်။',
    'Cash In / Cash Out workflow': 'Cash In / Cash Out လုပ်ငန်းစဉ်',
    'Cash In cancelled and Teller float/account state reversed.':
        'Cash In ကို ပယ်ဖျက်ပြီး Teller ငွေခွဲနှင့် အကောင့်အခြေအနေကို မူလအတိုင်း ပြန်လည်ညှိပြီးပါပြီ။',
    'Cashier handoff notes are not ready.':
        'Cashier ထံ အပ်မည့် ငွေစက္ကူစာရင်း အဆင်သင့်မဖြစ်သေးပါ။',
    'Cashier review mode is read-only.':
        'Cashier စစ်ဆေးမှုအခြေအနေတွင် ဖတ်ရှုရန်သာ ခွင့်ပြုထားပါသည်။',
    'Change from my teller vault': 'မိမိ Teller ငွေခွဲမှ ပြန်အမ်းငွေ',
    'Checking your access…': 'ဝင်ရောက်ခွင့် စစ်ဆေးနေပါသည်…',
    'Choose a Receive Money account.': 'ငွေလက်ခံမည့်အကောင့်ကို ရွေးပါ။',
    'Choose a Send Money account.': 'ငွေပို့မည့်အကောင့်ကို ရွေးပါ။',
    'Choose an Exchange account.': 'ငွေလဲအကောင့်ကို ရွေးပါ။',
    'Choose the KPay account first.': 'KPay အကောင့်ကို အရင်ရွေးပါ။',
    'Choose the account to credit.': 'ငွေဝင်မည့်အကောင့်ကို ရွေးပါ။',
    'Choose the fee account.': 'ဝန်ဆောင်ခအကောင့်ကို ရွေးပါ။',
    'Close Floats': 'ငွေခွဲစာရင်းများ ပိတ်မည်',
    'Closing & Reports': 'စာရင်းပိတ်ခြင်းနှင့် အစီရင်ခံစာများ',
    'Complete the required fields.':
        'လိုအပ်သောအချက်အလက်များကို ပြည့်စုံအောင် ဖြည့်ပါ။',
    'Confirm hand back with PIN': 'PIN ဖြင့် ပြန်အပ်ငွေ အတည်ပြုမည်',
    'Continue to workspace': 'လုပ်ငန်းခွင်သို့ ဆက်သွားမည်',
    'Count at least the fee amount received from the customer.':
        'ဖောက်သည်ထံမှ လက်ခံထားသော ဝန်ဆောင်ခပမာဏ အနည်းဆုံးပြည့်မီအောင် ရေတွက်ပါ။',
    'Count the MMK paid from the teller vault.':
        'Teller ငွေခွဲမှ ပေးမည့် MMK ငွေစက္ကူများကို ရေတွက်ပါ။',
    'Count the cash paid to the customer until it matches the amount.':
        'ဖောက်သည်ထံ ပေးမည့်ငွေကို သတ်မှတ်ပမာဏနှင့် ကိုက်ညီအောင် ရေတွက်ပါ။',
    'Count the cash you are handing back to the cashier once, then confirm with your PIN.':
        'Cashier ထံ ပြန်အပ်မည့်ငွေကို တစ်ကြိမ်တည်း ရေတွက်ပြီး PIN ဖြင့် အတည်ပြုပါ။',
    'Count the customer cash.': 'ဖောက်သည်ပေးသော ငွေသားကို ရေတွက်ပါ။',
    'Count the exact change to return to the customer.':
        'ဖောက်သည်ထံ ပြန်အမ်းမည့်ငွေကို အတိအကျ ရေတွက်ပါ။',
    'Counter float': 'ကောင်တာငွေခွဲ',
    'Doctor Phone Operations': 'Doctor Phone လုပ်ငန်းစီမံခန့်ခွဲမှု',
    'Enter an amount.': 'ပမာဏထည့်ပါ။',
    'Enter customer name.': 'ဖောက်သည်အမည် ထည့်ပါ။',
    'Enter customer phone.': 'ဖောက်သည်ဖုန်းနံပါတ် ထည့်ပါ။',
    'Enter the Cash In amount.': 'Cash In ပမာဏ ထည့်ပါ။',
    'Enter the customer source account number.':
        'ဖောက်သည်၏ မူလအကောင့်နံပါတ် ထည့်ပါ။',
    'Enter the recipient account number.': 'လက်ခံသူအကောင့်နံပါတ် ထည့်ပါ။',
    'Enter the recipient name.': 'လက်ခံသူအမည် ထည့်ပါ။',
    'Enter the transfer amount.': 'ငွေလွှဲပမာဏ ထည့်ပါ။',
    'Enter your password': 'စကားဝှက် ထည့်ပါ',
    'Enter your username': 'အသုံးပြုသူအမည် ထည့်ပါ',
    'Every main vault denomination movement.':
        'Main Vault ငွေစက္ကူရွှေ့ပြောင်းမှုအားလုံး။',
    'Fee Rules': 'ဝန်ဆောင်ခ စည်းမျဉ်းများ',
    'Float history': 'ငွေခွဲမှတ်တမ်း',
    'For authorised Doctor Phone staff only.':
        'ခွင့်ပြုထားသော Doctor Phone ဝန်ထမ်းများအတွက်သာ။',
    'Issue Float': 'ငွေခွဲ ထုတ်ပေးမည်',
    'Keep the main vault, teller floats, Cash In, Cash Out and reconciliation in sync.':
        'Main Vault၊ Teller ငွေခွဲ၊ Cash In၊ Cash Out နှင့် စာရင်းညှိခြင်းတို့ကို တစ်ပြိုင်နက် ကိုက်ညီအောင် စီမံပါ။',
    'LEGACY / UNLINKED': 'အဟောင်း / မချိတ်ဆက်ထား',
    'Local demo accounts only. Tap a role to fill the form.':
        'Local demo အကောင့်များအတွက်သာ။ Form ဖြည့်ရန် role ကို ရွေးပါ။',
    'MISSING CASH LOG': 'ငွေသားမှတ်တမ်း မရှိပါ',
    'Main vault control': 'Main Vault ထိန်းချုပ်မှု',
    'Master Data': 'အခြေခံဒေတာ',
    'My Float': 'ကိုယ်ပိုင်ငွေခွဲ',
    'N/A (VERIFY)': 'မသက်ဆိုင်ပါ (စစ်ဆေးရန်)',
    'Ngwe Lwe System': 'Ngwe Lwe System',
    'One clear view for every counter':
        'ကောင်တာတိုင်းအတွက် ရှင်းလင်းသော အနှစ်ချုပ်မြင်ကွင်း',
    'Pending receipt': 'လက်ခံရန် စောင့်ဆိုင်းနေသည်',
    'Quick sign in': 'အမြန်ဝင်ရောက်ရန်',
    'Receive Float': 'ငွေခွဲ လက်ခံမည်',
    'Receive float': 'ငွေခွဲ လက်ခံမည်',
    'Received cash is short.': 'လက်ခံရရှိသော ငွေသား မပြည့်ပါ။',
    'Reject float request': 'ငွေခွဲတောင်းဆိုမှု ပယ်ချမည်',
    'Reject with PIN': 'PIN ဖြင့် ပယ်ချမည်',
    'Return Cash': 'ငွေပြန်အပ်မည်',
    'Return cash': 'ငွေပြန်အပ်မည်',
    'Review cashier-entered notes, then receive or reject with your PIN.':
        'Cashier ထည့်ထားသော ငွေစက္ကူစာရင်းကို စစ်ပြီး PIN ဖြင့် လက်ခံ သို့မဟုတ် ပယ်ချပါ။',
    'Review your float sessions and additional issues.':
        'မိမိ၏ ငွေခွဲ session များနှင့် ထပ်မံထုတ်ပေးမှုများကို စစ်ဆေးပါ။',
    'Secure connection': 'လုံခြုံသော ချိတ်ဆက်မှု',
    'Secure staff access': 'ဝန်ထမ်းများအတွက် လုံခြုံသော ဝင်ရောက်ခွင့်',
    'Selected account balance is not enough.':
        'ရွေးထားသောအကောင့်တွင် လက်ကျန် မလုံလောက်ပါ။',
    'Sign in to manage today’s money movement with confidence.':
        'ယနေ့ ငွေလှုပ်ရှားမှုများကို စနစ်တကျ စီမံရန် ဝင်ရောက်ပါ။',
    'Staff & Access': 'ဝန်ထမ်းနှင့် ဝင်ရောက်ခွင့်',
    'Staff account': 'ဝန်ထမ်းအကောင့်',
    'Teller History': 'Teller မှတ်တမ်း',
    'Teller float tracking': 'Teller ငွေခွဲ စောင့်ကြည့်မှု',
    'Vault Stock': 'ငွေတိုက်လက်ကျန်',
    'We could not sign you in. Check your username and password.':
        'စနစ်ထဲ ဝင်ရောက်၍ မရပါ။ အသုံးပြုသူအမည်နှင့် စကားဝှက်ကို စစ်ဆေးပါ။',
    'Welcome back': 'ပြန်လည်ကြိုဆိုပါသည်',
    'With cashier': 'Cashier ထံတွင်',
    'Your PIN rejects this incoming float and returns it to the main vault.':
        'သင့် PIN ဖြင့် ဝင်လာသောငွေခွဲကို ပယ်ချပြီး Main Vault သို့ ပြန်ပို့ပါမည်။',
    'Your session expired. Please sign in again.':
        'Session သက်တမ်းကုန်သွားပါပြီ။ ပြန်လည်ဝင်ရောက်ပါ။',
    'Your session is protected by Laravel session authentication.':
        'သင့် session ကို Laravel session authentication ဖြင့် ကာကွယ်ထားပါသည်။',
    'Your counter balance and daily work at a glance.':
        'မိမိကောင်တာလက်ကျန်နှင့် နေ့စဉ်လုပ်ငန်းများကို တစ်နေရာတည်းတွင် ကြည့်ရှုနိုင်ပါသည်။',
    'exchange rate will be permanently deleted.':
        'ငွေလဲနှုန်းကို အပြီးတိုင် ဖျက်ပါမည်။',
    'owner deposit to cashier vault': 'Owner မှ Cashier Vault သို့ ငွေသွင်းမှု',
    'owner withdrawal from cashier vault':
        'Owner မှ Cashier Vault မှ ငွေထုတ်မှု',
    notes: 'ငွေစက္ကူ',
    Showing: 'ပြသနေသည်',
    to: 'မှ',
    of: 'စုစုပေါင်း',
    is: 'သည်',
    'issue(s) already waiting for Teller receipt.':
        'Teller လက်ခံရန် စောင့်နေသော ထုတ်ပေးမှု ရှိပြီးသားဖြစ်ပါသည်။',
    '· Owner authorization': '· Owner အတည်ပြုမှု',
    '· this creates another pending issue.':
        '· ထပ်မံလက်ခံရန် စောင့်ဆိုင်းစာရင်းတစ်ခု ဖန်တီးပါမည်။',
};

function normalize(value: string): string {
    return value.replace(/\s+/g, ' ').trim();
}

function dynamicMyanmar(value: string): string | null {
    let m = value.match(
        /^Showing\s+(\d+)\s+to\s+(\d+)\s+of\s+(\d+)\s+(entries|transactions)$/i,
    );
    if (m) {
        return `${m[1]} မှ ${m[2]} အထိ / စုစုပေါင်း ${m[3]} ${m[4].toLowerCase() === 'entries' ? 'စာရင်း' : 'ငွေလုပ်ငန်းစာရင်း'}`;
    }

    m = value.match(/^Float\s+#(\d+)$/i);
    if (m) return `ငွေခွဲ #${m[1]}`;

    m = value.match(
        /^Float\s+#(\d+)\s+is\s+(.+)\.\s+It must be received\/rejected or reconciled before another issue\.$/i,
    );
    if (m)
        return `ငွေခွဲ #${m[1]} သည် ${translateLegacyText(m[2], 'mm')} အခြေအနေတွင်ရှိပါသည်။ ထပ်မံထုတ်ပေးမီ လက်ခံ/ပယ်ချခြင်း သို့မဟုတ် စာရင်းညှိခြင်း ပြီးစီးရပါမည်။`;

    m = value.match(
        /^Active Float\s+#(\d+)\s+·\s+this creates another pending issue\.$/i,
    );
    if (m)
        return `အသုံးပြုနေသော ငွေခွဲ #${m[1]} · ထပ်မံလက်ခံရန် စောင့်ဆိုင်းစာရင်းတစ်ခု ဖန်တီးပါမည်။`;

    m = value.match(/^Review handoff\s+#(\d+)$/i);
    if (m) return `အပ်ငွေ #${m[1]} စစ်ဆေးရန်`;

    m = value.match(/^Verify Float\s+#(\d+)$/i);
    if (m) return `ငွေခွဲ #${m[1]} စစ်ဆေးရန်`;

    m = value.match(/^([\d,.]+)\s+MMK\s+awaiting review\s+→$/i);
    if (m) return `${m[1]} MMK စစ်ဆေးရန် စောင့်နေသည် →`;

    m = value.match(/^([\d,.]+)\s+MMK\s+expected\s+→$/i);
    if (m) return `မျှော်မှန်းငွေ ${m[1]} MMK →`;

    m = value.match(/^available\s+·\s+([\d,.]+)\s+MMK$/i);
    if (m) return `အသုံးပြုနိုင် · ${m[1]} MMK`;

    m = value.match(/^Not enough stock for:\s+([\d,.]+)\s+MMK$/i);
    if (m) return `${m[1]} MMK အတွက် ငွေစက္ကူလက်ကျန် မလုံလောက်ပါ။`;

    m = value.match(/^Total:\s+([\d,.]+)\s+MMK$/i);
    if (m) return `စုစုပေါင်း: ${m[1]} MMK`;

    m = value.match(
        /^Only one Cashier account is allowed\. Current:\s*(.+)\.$/i,
    );
    if (m)
        return `Cashier အကောင့်တစ်ခုသာ အသုံးပြုခွင့်ရှိပါသည်။ လက်ရှိ: ${m[1]}။`;

    return null;
}

export function translateLegacyText(
    value: string,
    locale: 'en' | 'mm',
): string {
    if (locale === 'en') return value;
    const trimmed = normalize(value);
    return mm[trimmed] ?? dynamicMyanmar(trimmed) ?? value;
}

type TextState = { original: string; translated: string };
type AttrState = Record<string, { original: string; translated: string }>;

const textState = new WeakMap<Text, TextState>();
const attrState = new WeakMap<Element, AttrState>();
const translatableAttrs = ['placeholder', 'title', 'aria-label'] as const;

function shouldSkip(node: Node): boolean {
    const el =
        node.nodeType === Node.ELEMENT_NODE
            ? (node as Element)
            : node.parentElement;
    if (!el) return false;
    return Boolean(
        el.closest(
            'script, style, code, pre, textarea, [contenteditable="true"], [data-no-auto-i18n]',
        ),
    );
}

function processText(node: Text, locale: 'en' | 'mm'): void {
    if (shouldSkip(node)) return;
    const current = node.nodeValue ?? '';
    if (!/[A-Za-z]/.test(current) && locale === 'mm') return;

    const leading = current.match(/^\s*/)?.[0] ?? '';
    const trailing = current.match(/\s*$/)?.[0] ?? '';
    const visible = current.trim();
    if (!visible) return;

    const state = textState.get(node);
    let original = state?.original ?? visible;
    if (state && visible !== state.translated && visible !== state.original) {
        original = visible;
    }

    const translated = translateLegacyText(original, locale);
    textState.set(node, { original, translated });
    const next = `${leading}${translated}${trailing}`;
    if (next !== current) node.nodeValue = next;
}

function processElement(el: Element, locale: 'en' | 'mm'): void {
    if (shouldSkip(el)) return;
    const state = attrState.get(el) ?? {};

    for (const attr of translatableAttrs) {
        const current = el.getAttribute(attr);
        if (!current) continue;
        const prior = state[attr];
        let original = prior?.original ?? current;
        if (
            prior &&
            current !== prior.translated &&
            current !== prior.original
        ) {
            original = current;
        }
        const translated = translateLegacyText(original, locale);
        state[attr] = { original, translated };
        if (translated !== current) el.setAttribute(attr, translated);
    }

    attrState.set(el, state);
}

function walk(root: Node, locale: 'en' | 'mm'): void {
    if (root.nodeType === Node.TEXT_NODE) {
        processText(root as Text, locale);
        return;
    }
    if (
        root.nodeType !== Node.ELEMENT_NODE &&
        root.nodeType !== Node.DOCUMENT_FRAGMENT_NODE
    )
        return;

    if (root.nodeType === Node.ELEMENT_NODE)
        processElement(root as Element, locale);
    const walker = document.createTreeWalker(
        root,
        NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT,
    );
    let node = walker.nextNode();
    while (node) {
        if (node.nodeType === Node.TEXT_NODE) processText(node as Text, locale);
        else processElement(node as Element, locale);
        node = walker.nextNode();
    }
}

let titleState: { original: string; translated: string } | null = null;

function localizeDocumentTitle(locale: 'en' | 'mm'): void {
    if (!document.title) return;

    const current = document.title;
    let original = titleState?.original ?? current;
    if (
        titleState &&
        current !== titleState.translated &&
        current !== titleState.original
    ) {
        original = current;
    }

    const parts = original.split(' - ');
    const translated =
        parts.length > 1
            ? [translateLegacyText(parts[0], locale), ...parts.slice(1)].join(
                  ' - ',
              )
            : translateLegacyText(original, locale);

    titleState = { original, translated };
    if (translated !== current) document.title = translated;
}

let installed = false;

export function installEnterpriseDomLocalization(root: HTMLElement): void {
    if (installed || typeof window === 'undefined') return;
    installed = true;

    const { lang } = useLocale();
    const apply = () => {
        document.documentElement.lang = lang.value === 'mm' ? 'my' : 'en';
        document.cookie = `ngwe_lwe_locale=${lang.value}; Path=/; Max-Age=31536000; SameSite=Lax`;
        localizeDocumentTitle(lang.value);
        walk(root, lang.value);
    };

    apply();
    watch(lang, () => queueMicrotask(apply));

    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'characterData') {
                processText(mutation.target as Text, lang.value);
                continue;
            }
            if (
                mutation.type === 'attributes' &&
                mutation.target instanceof Element
            ) {
                processElement(mutation.target, lang.value);
                continue;
            }
            for (const node of mutation.addedNodes) walk(node, lang.value);
        }
    });

    observer.observe(root, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: [...translatableAttrs],
    });

    const headObserver = new MutationObserver(() =>
        localizeDocumentTitle(lang.value),
    );
    headObserver.observe(document.head, {
        childList: true,
        subtree: true,
        characterData: true,
    });
}
