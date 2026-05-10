-- Boss-Secretary busy check
-- Usage in dialplan: <action application="lua" data="app/rest_api/actions/boss-secretary-busy-check.lua ${destination_number} ${domain_name}"/>
-- Sets channel variable boss_is_busy=true or boss_is_busy=false

local ext = argv[1]
local domain = argv[2]

if not ext or not domain then
    session:setVariable("boss_is_busy", "false")
    return
end

-- Use show calls to find if the extension has active calls
local api = freeswitch.API()
local result = api:execute("show", "calls as delim |")

local busy = false
if result then
    -- Check if extension appears as either caller or callee in active calls
    local pattern = ext .. "@" .. domain
    for line in result:gmatch("[^\n]+") do
        if line:find(pattern, 1, true) then
            busy = true
            break
        end
    end
end

session:setVariable("boss_is_busy", busy and "true" or "false")
