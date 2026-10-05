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
