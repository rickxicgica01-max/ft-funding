# FT Funding Direct Capital — Website

Static marketing site built with [Astro](https://astro.build), with scroll motion
powered by [GSAP](https://gsap.com) (ScrollTrigger) and smooth scrolling via
[Lenis](https://github.com/darkroomengineering/lenis).

## Commands

> Node.js is required (installed via Homebrew: `brew install node`).

| Command           | What it does                                  |
| ----------------- | --------------------------------------------- |
| `npm install`     | Install dependencies                          |
| `npm run dev`     | Start the dev server at http://localhost:4321 |
| `npm run build`   | Build the production site into `dist/`        |
| `npm run preview` | Preview the production build locally          |

## Project structure

```
src/
├── layouts/
│   └── BaseLayout.astro   # HTML shell: <head>, fonts, global CSS, motion bootstrap, shared <Footer>
├── components/
│   ├── Nav.astro          # Shared top navigation. <Nav /> = transparent (hero), <Nav solid /> = navy sticky bar
│   └── Footer.astro       # Shared footer
├── pages/
│   ├── index.astro        # Home page (/)
│   └── about.astro        # Example second page (/about)
├── scripts/
│   └── motion.js          # GSAP + Lenis setup
└── styles/
    └── global.css         # All site styles
public/                    # Static assets served as-is (drop self-hosted images/fonts here)
legacy/                    # The original single-file index.html + styles.css (kept for reference)
```

## Adding a new page

Create a file in `src/pages/` — the filename becomes the URL
(`src/pages/services.astro` → `/services`). Start from this template:

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
import Nav from '../components/Nav.astro';
---
<BaseLayout title="Services — FT Funding" description="...">
  <Nav solid />
  <!-- your content -->
</BaseLayout>
```

Edit `Nav.astro` / `Footer.astro` once and every page updates.

## Adding motion to an element

- `data-reveal` — fades + slides the element in when it scrolls into view.
- `data-reveal-stagger` — put on a parent (e.g. a card grid) to stagger its
  direct `[data-reveal]` children in sequence.

Both respect `prefers-reduced-motion` and degrade gracefully with JS disabled.
For custom animations, edit `src/scripts/motion.js`.

## Deploying

This builds to plain static files (`dist/`), so any static host works:

- **Cloudflare Pages / Netlify / Vercel** — connect the git repo and set:
  - Build command: `npm run build`
  - Output directory: `dist`
- Or run `npm run build` and drag the `dist/` folder onto Netlify.

## Notes

- The hero/card background images and avatars currently load from external CDNs
  (Unsplash). For production, download them into `public/` and update the URLs in
  `src/styles/global.css` so the site doesn't depend on those external URLs.
