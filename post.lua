requests = function()
    if math.random() < 0.5 then
        return wrk.format("GET", "/slow")
    else
        return wrk.format("GET", "/ping")
    end
end