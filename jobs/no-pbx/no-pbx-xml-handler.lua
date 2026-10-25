--	no-pbx-xml-handler.lua
--
--	lua.conf.xml points xml-handler-script here instead of at "app.lua xml_handler", so FreeSWITCH asks
--	this script for every configuration, dialplan, directory and languages lookup. It runs the FusionPBX
--	handler unchanged, then, for sofia.conf only, adds the no-pbx profile before </profiles>.
--
--	sofia.conf is only asked for when FreeSWITCH starts or a SIP profile is started, restarted or
--	rescanned; calls on the no-pbx profile park inline and never come through here.

--run the FusionPBX handler exactly as "app.lua xml_handler" would
	require "resources.functions.config";
	argv = {[0] = "app.lua", [1] = "xml_handler"};
	dofile(scripts_dir .. "/app.lua");

--add the no-pbx profile to sofia.conf
	if (XML_REQUEST["section"] == "configuration" and XML_REQUEST["key_value"] == "sofia.conf") then
		local profile_file = "/etc/freeswitch/no-pbx/no-pbx-profile.xml";
		if (XML_STRING == nil) then
			freeswitch.consoleLog("warning", "[no-pbx] FusionPBX returned no sofia.conf, the no-pbx profile is not added\n");
		elseif (XML_STRING:find('<profile name="no-pbx"', 1, true)) then
			freeswitch.consoleLog("warning", "[no-pbx] sofia.conf already has a no-pbx profile, not adding it twice\n");
		else
			local file = io.open(profile_file, "r");
			if (file == nil) then
				freeswitch.consoleLog("notice", "[no-pbx] " .. profile_file .. " not found, the no-pbx profile is not added\n");
			else
				local profile = file:read("*a");
				file:close();
				--a function as the replacement, so % in the profile is taken literally
				local count;
				XML_STRING, count = XML_STRING:gsub("</profiles>", function() return profile .. "</profiles>"; end, 1);
				if (count == 0) then
					freeswitch.consoleLog("err", "[no-pbx] sofia.conf has no </profiles>, the no-pbx profile is not added\n");
				end
			end
		end
	end
