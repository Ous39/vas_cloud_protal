# How to build a USSD menu in VAS Cloud

Everything is on **Infrastructure → USSD Menu Builder**. You never need to restart anything: customers see an item the moment it is **Active**.

## The 5-minute way

1. **Pick the short code** at the top (or type a new one, e.g. `*123#`, and press *Switch / Start*).
2. **Quick add** (right side): type your list, one item per line.
   ```
   KAA Bundle
   Sakan 7 days   | catalog | Sakan 7 days
   Sakan 30 days  | catalog | Sakan 30 days; Sakan Data 30 days
   Daily 1GB      | offer   | 40154
   How to play    | end     | Answer 5 questions, score 4+ to win
   Comium Quiz    | quiz    | comium_trivia
   Shared Bundle  | shared  | Seddo
   Buy for other  | other
   ```
   A line with only a name makes a **submenu**. Choose *"Add them inside"* to put the list under an existing submenu. Items are added as **Drafts** (only visible in the Simulator).
3. **Menu check** (left, top) tells you what would go wrong on a phone. Fix the red items; look at the yellow ones.
4. **Try it** (button, top right) opens the Simulator on this short code. Dial through the whole menu.
5. **Activate all drafts** turns everything on. Done.

If a mistake gets through: **History** (right) keeps every change. Press **Restore** on the version you want back — the menu you replace is kept as an inactive archive, nothing is ever deleted.

## The item types

| You want… | Type | Notes |
|---|---|---|
| A list of more choices | **Submenu** | Put items inside it with the **+** button. |
| All the offers of a catalogue sub-category | **Catalogue list** (`catalog`) | Stays up to date by itself: new or switched-off offers appear/disappear. Cheapest first, 5 per screen with *More*. Several sub-categories separated by `;`. |
| One specific offer | **Offer** (`offer`) | Shows name and price, asks *1. Confirm / 2. Cancel*, then buys. |
| A message / help screen | **Message** (`end`) | Ends the session. Use **Screen text** for the wording; the label is only the line in the parent's menu. |
| Buying for someone else | **Buy for another number** (`other`) | Asks for the number, then shows the main menu again. |
| Seddo shared bundle | **Shared Bundle service** (`shared`) | Buy, add sharing number, balance, numbers. Settings on the USSD Proxy page. |
| A game | **Quiz** (`quiz`) | Questions are managed on **USSD Quiz**. |

**Label vs screen text.** The *label* is the line in the menu above ("Sakan Bundles"). The optional *screen text* is what shows when someone opens it ("Choose your Sakan bundle:"). Keep labels short (under 45 characters).

**A phone screen holds about 180 characters.** The Menu check warns when a list is too long — move some items into a submenu.

## Special menus: Service Flows (Admin → Infrastructure → Service Flows)

For anything more than a list — asking for a number, reading something from Hera, a purchase that can succeed, run out of balance or fail, an offer of a loan — build a **service flow**, then put it in a menu with the item type **Service flow**.

A flow is a set of named **steps**. Each step is one of:

| Step | What the customer sees / what happens |
|---|---|
| **Choices** | A list of options; each option goes to another step. |
| **Offers** | The offers of catalogue sub-category(ies); the one they pick fills `{offer.name}`, `{offer.price_d}`, `{offer.validity}`, `{offer.code}`… If there is only one offer it can be chosen for them. |
| **Ask** | They type something: a phone number (shown back without the 220), digits, or a short text. Kept as `{name}` (`{name_local}`, `{name_raw}` too). |
| **Look up** | Reads something from Hera and shows it, e.g. `Your balance is: {result.balance}`. |
| **Confirm** | Your text; **1** = yes, **0 or 2** = no. |
| **Call** | The call that **changes something**. It runs **once per customer session**. Its **outcome — success, low balance, or any other failure — decides the next step**, and the reply is available as `{reply_text}` and `{result.…}`. |
| **Message** | Shows text and ends the session. |

Every step says where to go next; `@exit` leaves the flow and returns to the menu it was opened from. **0** always goes back one step.

**Example — buy an offer, with a loan offered if the balance is too low:** choices → offers → confirm → **call (buy)** → *success* → message "Your subscription is successful"; *low balance* → confirm "You don't have enough balance… take a loan and subscribe? 1/2" → **call (take loan)** → message; *other failure* → message "Subscription failed". The starter template **"Purchase, with a loan offered…"** is exactly this with the API calls left to fill in.

**Getting a call right.** On the same page, **Connections** holds the address + headers of the system a call goes to (headers are encrypted and never shown again). Paste a Mobius `Sending request :{…}` log line into **"Get a request body from a Mobius log line"** and the page builds the request body for you (caller's number, IMSI, addresses become placeholders; keys are ignored). Then in the call step choose the connection, give the path (the last part of the address) and paste the body.

**Safety.**
- A flow starts in **Test**: calls are rehearsed (you choose whether they end as success, low balance or failure) and nothing is sent. Switch to **Live** only when the check says all good.
- A call with no connection, path or body stays switched off in Live and takes the failure path.
- **Try it** (right side of the flow page) walks the whole flow, pretending Hera says success / low balance then success / failure, so every branch can be seen before any phone is used. The **USSD Simulator** has the same choice for flows inside a menu.
- Every call is recorded (outcome, the request we sent with the IMSI shortened, Hera's reply) under **Recent calls**, and every change is in **History**.

## Direct codes

Every item in the main menu can be dialled straight from the phone: put the number(s) you would have typed after the short code, each after a `*`.

| Dial | Lands on |
|---|---|
| `*9606*9090#` | the main menu |
| `*9606*9090*1#` | 1. KAA Bundle |
| `*9606*9090*2#` | 2. Sakan Bundles |
| `*9606*9090*2*3#` | Sakan Bundles → its 3rd item |

It is exactly the same as dialling the short code and then typing those numbers, so it follows the menu as it is today: if you add or move an item, its direct code changes with it. The **Menu Builder** shows each item's direct code (⚡) next to it, and the **Simulator** accepts them in the short-code box. Only digits count as choices (`*9606*9090*abc#` is not served), and a short code that has its own menu always wins over this rule.

**Mobius must also route these strings to the portal.** If a phone answers "connection problem or invalid MMI code", the dial string has not been registered in Mobius: register each direct code (or a wildcard for `*9606*9090*`) on the same PROXY menu.

## Buttons on each item

↑ ↓ move · ✎ edit · **+** add an item inside (submenus) · ⧉ copy it and everything inside it, as a Draft · ⏻ turn on/off.

## Other tools (right side, bottom)

- **Copy this whole menu to another short code** — arrives as Drafts.
- **Import from JSON** — paste a menu in the `Export JSON` format; replaces the code's menu (old one archived).

## Before a new short code works on phones

1. Create the menu in **Mobius** as a **PROXY** menu with that short code, pointing at the portal address shown on the **USSD Proxy** page (the same one as the other menus).
2. Build the menu here. The portal serves whichever short code was dialled, as long as it has Active items.

## Who can do what

*View* is for everyone who can see tables. *Building* needs the `manage_ussd_menus` permission (Admin → Users).

## When something looks wrong

Open **Menu check** first — it names the item and says why. Typical messages:

- *"…lists offers from [X] but none are Active"* — the sub-category name is misspelt, or its offers are switched off in the catalogue.
- *"…sells offer N, which is missing or not Active"* — the offer is off or deleted; pick another or switch it on.
- *"…a submenu with nothing Active inside"* — turn something on inside it, or turn the submenu off.
- *"…too long for one phone screen"* — split the list.
- *"The USSD Proxy is not fully live"* — endpoint off, Capture mode, or not answering through Mobius (USSD Proxy page).
