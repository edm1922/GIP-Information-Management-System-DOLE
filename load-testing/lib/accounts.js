/** Parses and validates the test-account list (no credentials are ever logged). */

/**
 * Accepts either the provisioned `{accounts: [...]}` document or a bare array.
 * Every entry must carry a username and password; no defaults are invented so a
 * misconfigured file fails the run immediately instead of hammering with one
 * account.
 */
export function parseAccounts(rawText) {
  let parsed;
  try {
    parsed = JSON.parse(rawText);
  } catch (error) {
    throw new Error(`Accounts file is not valid JSON: ${error.message}`);
  }

  const list = Array.isArray(parsed) ? parsed : parsed && parsed.accounts;
  if (!Array.isArray(list) || list.length === 0) {
    throw new Error("Accounts file must contain a non-empty `accounts` array.");
  }

  const accounts = list.map((entry, index) => {
    const position = index + 1;
    if (!entry || typeof entry.username !== "string" || entry.username.trim() === "") {
      throw new Error(`Account #${position} is missing a username.`);
    }
    if (typeof entry.password !== "string" || entry.password === "") {
      throw new Error(`Account #${position} (${entry.username}) is missing a password.`);
    }

    return {
      username: entry.username.trim(),
      password: entry.password,
    };
  });

  const seen = new Set();
  for (const account of accounts) {
    if (seen.has(account.username)) {
      throw new Error(`Duplicate test account username: ${account.username}`);
    }
    seen.add(account.username);
  }

  return accounts;
}

/** Deterministic per-VU account assignment: VU 1 -> account 0, VU 2 -> account 1, ... */
export function accountForVu(accounts, vuId) {
  return accounts[(vuId - 1) % accounts.length];
}
