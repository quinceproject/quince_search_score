# Quince Search Score

**What customers search for, and what they click after searching, for PrestaShop 9.2.**

Quince Search Score counts the phrases customers search for in the shop and the
products they open from the search results. Each click adds a point to the product's
score. The shop sees both lists in the back office, can set a product's score by
hand, and other Quince modules use the score to order products.

> Quince Search Score is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **Search phrases.** Every phrase searched on the results page is stored once, in
  lower case, with a counter of how many times it was searched.
- **Clicks after searching.** A click on a product in the search results adds one
  point to that product's score. Moving between result pages, filters and sorting
  keeps the search context; leaving the search and product pages ends it.
- **Back office lists.** Two tabs: products with their score, filtered by name,
  category and score range, and phrases with their count. Both sort by column and
  delete the selected rows.
- **Score set by hand.** Staff can change a product's score directly, for example to
  push a product up or to reset one.
- **Used by other Quince modules.** When installed together:
  - [Quince Search](https://github.com/quinceproject/quince_search) uses the score
    only to break ties between equally relevant results. It never outranks a better
    match.
  - Quince Product Section offers a "most searched" product section ordered by the
    score.
- **Clean uninstall.** Uninstalling removes both tables with all phrases and scores.

## What is stored, and what is not

The module is meant to stay out of personal data:

- **Stored:** the text of each searched phrase (lower case) with a counter and the
  date it was first and last searched; per product, the score and the dates.
- **Not stored:** IP addresses, customer or guest IDs, cookies, or anything that links
  a phrase or a click to a person.
- **In the browser:** the search context (the phrase and the time) is kept in
  `sessionStorage` for the current tab only, and is cleared when the customer leaves
  the search and product pages. It is never sent anywhere except as the phrase itself.

A phrase is stored as the customer typed it. If someone types personal data into the
search field, such as an e-mail address, that text ends up in the phrase list; staff
can delete it there.

The tracking endpoint is public and anonymous, as the customer's browser calls it.
It accepts only XHR requests and existing product IDs, but it does not limit how often
it is called, so the counters show trends rather than exact figures.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |

Quince Engine hosts the module's back office screen. The module declares it as a
dependency, so PrestaShop refuses to install Quince Search Score without it.

Click tracking reads the product ID from the product cards of the search results
(`data-id-product` on `.js-product-miniature`, `.product-miniature` or another
element). Themes based on the PrestaShop standard markup provide it, as does the
Quince theme.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_search_score.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_search_score/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_search_score` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Search Score and click **Install**.

**From Quince Engine** *(coming soon)*

The module list in Quince Engine will install the module straight from its release,
without downloading anything by hand.

**With Composer** *(coming soon)*

Installing with `composer require quinceproject/search-score` will work once the
package is published on Packagist.

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_search_score/releases).

## Configuration

There is nothing to set up. Tracking starts as soon as the module is installed.

Open **Quince project → Search Score** in the back office menu, or click
**Configure** next to the module in the Module Manager, to see the products and
phrases tabs.

## Architecture

- **`search-score-tracker.js`**, added to every page through `displayHeader`, detects
  the search results page itself and reports phrases and clicks.
- **`track` front controller** receives those reports and updates the counters.
  Other scripts can call `window.qssTrackPhrase(phrase)` and
  `window.qssTrackClick(idProduct)` directly.
- **`Repository`** keeps two tables, `quince_search_score_phrases` and
  `quince_search_score_products`.
- **Symfony controller and Twig** for the back office screen, inside the Quince
  Engine shell.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_search_score.git
cd quince_search_score
composer install
```

The folder must be called `quince_search_score`, the same as the module. A clone of
this repository already has that name.

To build an installable archive, zip the `quince_search_score/` folder **with its
`vendor/` directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
