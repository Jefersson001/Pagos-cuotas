import { defineConfig } from '@playwright/test';
export default defineConfig({testDir:'./tests/browser',globalSetup:'./tests/browser/server.js',workers:1,use:{baseURL:'http://127.0.0.1:8095',launchOptions:{executablePath:process.env.CHROME_PATH||'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'},viewport:{width:1440,height:1000}},reporter:'list'});
