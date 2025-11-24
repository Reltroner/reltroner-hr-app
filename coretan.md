🚀 How I Deployed a Laravel HRM App on Hostinger + Vercel (and Debugged Everything Along the Way)

Over the past few days, I completed an end-to-end deployment journey for my Reltroner HRM Laravel application using a split-hosting setup:

reltroner.com → hosted on Vercel

hrm.reltroner.com → hosted on Hostinger (Laravel backend)

The process wasn’t smooth, but it became an amazing debugging experience. Here are the biggest issues I encountered and how I solved them.


🐞 1. “Project Directory Is Not Empty”

Hostinger refuses Git deployment unless the target folder is completely empty.

Fix: Clean the directory, redeploy.


🐞 2. Laravel 500 Error on Subdomain

Visiting:

https://hrm.reltroner.com/login

Returned a blank 500 page.

Cause: Missing .env and APP_KEY.

Fix: Generate .env, create key, clear caches.


🐞 3. SQLite Error: “Database file does not exist”

Laravel tried to use SQLite in production.

Fix: Switch to MySQL in .env using the Hostinger credentials.


🐞 4. MySQL “Access Denied”

Laravel still used cached configurations.

Fix: Clear config + cache → Laravel loads the correct MySQL credentials.


🐞 5. reltroner.com Opening Hostinger Default Page

A DNS conflict caused the root domain to resolve to Hostinger instead of Vercel.

Fix:
✔ Switched nameservers to Vercel
✔ Pointed subdomain hrm to Hostinger via A record

Final mapping:

reltroner.com → Vercel

hrm.reltroner.com → Hostinger


🐞 6. Git Auto Deployment Not Working

Hostinger cannot auto-pull from GitHub using HTTPS.

Fix:
Use SSH Deploy Keys.
After adding the key to GitHub, Hostinger can auto-deploy on every git push. ✨


🐞 7. DNS Conflicts Across Two Hosting Providers

The domain and subdomain required separate infrastructures.

Fix:
✔ Vercel nameservers for the root domain
✔ Hostinger A record for the HRM subdomain

Result:
⚡ Front-end on Vercel
🛠 Back-end on Hostinger

Perfect split-hosting setup.


🎉 Final Result

✔ reltroner.com loads from Vercel
✔ hrm.reltroner.com loads from Hostinger
✔ Laravel HRM app runs smoothly with MySQL
✔ SSH-based auto-deployment works
✔ No more 500 errors
✔ A clean, reliable production configuration


🔥 Key Takeaways

DNS across two platforms is tricky, but definitely doable

Laravel production must have the correct .env & cache cleared

SSH Deploy Keys > manual uploads

Deployment debugging teaches more than any tutorial ever could

This journey reminded me why I love building systems 
Every bug is a puzzle. Every fix feels like leveling up. 


If you're working on Laravel deployment or multi-platform DNS setup, feel free to DM me, I'd be happy to help!

🔗 Project Repository
👉 https://github.com/Reltroner/reltroner-hr-app

🔖 Hashtags

#Laravel #WebDevelopment #FullStackDeveloper #DevOps #DNS #Deployment
#Vercel #Hostinger #GitHub #PHP #SoftwareEngineering #Debugging
#ProgrammerLife #TechJourney #ReltronerStudio