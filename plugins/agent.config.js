/**
 * Agent TARS configuration — DeepSeek V4 Pro
 * Auto-detected at startup. CLI equivalent:
 *   agent-tars --model.provider deepseek --model.id deepseek-v4-pro --model.apiKey $DEEPSEEK_API_KEY
 */
module.exports = {
  model: {
    provider: 'deepseek',
    id: 'deepseek-v4-pro',
    // Dragon exports ANTHROPIC_AUTH_TOKEN from the macOS Keychain.
    // Agent TARS needs this key for the DeepSeek provider.
    apiKey: process.env.DEEPSEEK_API_KEY || process.env.ANTHROPIC_AUTH_TOKEN,
  },
  workspace: process.cwd(),
  browser: {
    control: 'hybrid',
  },
};
