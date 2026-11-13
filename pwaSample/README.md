# 🧩 PWA Sample App

A simple example of a **Progressive Web App (PWA)** showing the basic setup including:
- Web App Manifest
- Service Worker Registration
- Offline Support
- Simple UI

## 📁 Folder Structure
```
pwa-sample/
│
├── index.html
├── manifest.json
├── sw.js
└── /icons/
    ├── icon-192.png
    └── icon-512.png
```

## 🚀 How to Run
1. Clone or download this folder.
2. Open the terminal and run a local server (required for service worker):
   ```bash
   npx serve
   ```
3. Open [http://localhost:3000](http://localhost:3000) in your browser.
4. Add it to your home screen on mobile — it’ll work offline!

## ⚙️ Explanation
- **index.html**: The main UI and registers the service worker.
- **manifest.json**: Defines how your app appears when installed (icon, name, color).
- **sw.js**: Handles caching and allows offline functionality.
- **icons/**: Contains app icons required for installation.

## 💡 Next Steps
You can extend this by adding:
- Network status indicator (online/offline)
- Push notifications
- Background sync
- App shell architecture
